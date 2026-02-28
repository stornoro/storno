<?php

namespace App\Service\Borderou;

use App\Entity\BorderouTransaction;
use App\Entity\Client;
use App\Entity\Company;
use App\Entity\Invoice;
use App\Entity\ProformaInvoice;
use App\Enum\DocumentStatus;
use App\Enum\InvoiceDirection;
use App\Enum\ProformaStatus;
use App\Repository\ClientRepository;
use App\Repository\InvoiceRepository;
use App\Repository\ProformaInvoiceRepository;
use Doctrine\ORM\EntityManagerInterface;

class BorderouMatchingService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InvoiceRepository $invoiceRepo,
        private readonly ClientRepository $clientRepo,
        private readonly ProformaInvoiceRepository $proformaRepo,
    ) {}

    /**
     * Match a single transaction against invoices and proformas in the company.
     *
     * @return array{confidence: string, invoice: ?Invoice, proformaInvoice: ?ProformaInvoice, client: ?Client}
     */
    public function matchTransaction(BorderouTransaction $tx, Company $company): array
    {
        $client = $this->findClient($tx, $company);
        $invoice = $this->findInvoice($tx, $company, $client);
        $proformaInvoice = null;

        // Only try proforma if no invoice found
        if (!$invoice) {
            $proformaInvoice = $this->findProformaInvoice($tx, $company, $client);
        }

        $confidence = $this->calculateConfidence($tx, $client, $invoice, $proformaInvoice);

        return ['confidence' => $confidence, 'invoice' => $invoice, 'proformaInvoice' => $proformaInvoice, 'client' => $client];
    }

    /**
     * Re-run matching on a set of transactions.
     *
     * @param BorderouTransaction[] $transactions
     */
    public function rematchTransactions(array $transactions, Company $company): void
    {
        foreach ($transactions as $tx) {
            if ($tx->getStatus() !== 'unsaved') {
                continue;
            }

            $result = $this->matchTransaction($tx, $company);
            $tx->setMatchConfidence($result['confidence']);
            $tx->setMatchedInvoice($result['invoice']);
            $tx->setMatchedProformaInvoice($result['proformaInvoice']);
            $tx->setMatchedClient($result['client']);
        }

        $this->em->flush();
    }

    private function findClient(BorderouTransaction $tx, Company $company): ?Client
    {
        // 1. Exact CIF match
        $cif = $tx->getClientCif();
        if ($cif) {
            $client = $this->clientRepo->findByCui($company, $cif);
            if ($client) {
                return $client;
            }
        }

        // 2. Try matching via invoice receiverCif / receiverName
        $clientName = $tx->getClientName();
        if ($clientName) {
            // Exact name match
            $client = $this->findClientByName($company, $clientName);
            if ($client) {
                return $client;
            }
        }

        // 3. AWB-based: look for invoice with matching orderNumber, get its client
        $awb = $tx->getAwbNumber();
        if ($awb) {
            $invoice = $this->findInvoiceByAwb($company, $awb);
            if ($invoice?->getClient()) {
                return $invoice->getClient();
            }
        }

        return null;
    }

    private function findInvoice(BorderouTransaction $tx, Company $company, ?Client $client): ?Invoice
    {
        // 1. AWB match — look for invoice with orderNumber = AWB
        $awb = $tx->getAwbNumber();
        if ($awb) {
            $invoice = $this->findInvoiceByAwb($company, $awb);
            if ($invoice) {
                return $invoice;
            }
        }

        // 2. Invoice number match from bank statement description
        $docNumber = $tx->getDocumentNumber();
        if ($docNumber) {
            $invoice = $this->findInvoiceByNumber($company, $docNumber);
            if ($invoice) {
                return $invoice;
            }
        }

        // 3. Client + amount match
        if ($client) {
            return $this->findInvoiceByClientAndAmount($company, $client, $tx->getAmount());
        }

        // 4. Try matching by receiverName on invoices directly
        $clientName = $tx->getClientName();
        if ($clientName) {
            return $this->findInvoiceByReceiverNameAndAmount($company, $clientName, $tx->getAmount());
        }

        return null;
    }

    private function calculateConfidence(BorderouTransaction $tx, ?Client $client, ?Invoice $invoice, ?ProformaInvoice $proformaInvoice = null): string
    {
        if (!$client && !$invoice && !$proformaInvoice) {
            return 'no_match';
        }

        if ($invoice) {
            $balance = $invoice->getBalance();
            $amount = $tx->getAmount();

            // Exact match
            if (bccomp($amount, $balance, 2) === 0) {
                return 'certain';
            }

            // Within 5% tolerance
            $tolerance = bcmul($balance, '0.05', 2);
            $diff = bcsub($amount, $balance, 2);
            if (bccomp(abs((float) $diff), $tolerance, 2) <= 0) {
                return 'certain';
            }

            // AWB matched or invoice number matched but amount differs
            if ($tx->getAwbNumber() || $tx->getDocumentNumber()) {
                return 'attention';
            }

            return 'attention';
        }

        if ($proformaInvoice) {
            $proformaTotal = $proformaInvoice->getTotal();
            $amount = $tx->getAmount();

            // Exact match on proforma total
            if (bccomp($amount, $proformaTotal, 2) === 0) {
                return 'certain';
            }

            // Within 5% tolerance
            $tolerance = bcmul($proformaTotal, '0.05', 2);
            $diff = bcsub($amount, $proformaTotal, 2);
            if (bccomp(abs((float) $diff), $tolerance, 2) <= 0) {
                return 'certain';
            }

            return 'attention';
        }

        // Client found but no matching invoice or proforma
        return 'attention';
    }

    private function findProformaInvoice(BorderouTransaction $tx, Company $company, ?Client $client): ?ProformaInvoice
    {
        $conn = $this->em->getConnection();
        $companyId = $company->getId()->toRfc4122();

        // 1. Number match from documentNumber
        $docNumber = $tx->getDocumentNumber();
        if ($docNumber) {
            $row = $conn->fetchAssociative(
                "SELECT id FROM proforma_invoice
                 WHERE company_id = ? AND deleted_at IS NULL
                 AND status IN ('sent', 'accepted')
                 AND (number = ? OR number LIKE ?)
                 LIMIT 1",
                [$companyId, $docNumber, '%' . $docNumber]
            );
            if ($row) {
                return $this->em->find(ProformaInvoice::class, $row['id']);
            }
        }

        // 2. Client + amount match for sent/accepted proformas
        if ($client) {
            $clientId = $client->getId()->toRfc4122();
            $row = $conn->fetchAssociative(
                "SELECT id FROM proforma_invoice
                 WHERE company_id = ? AND deleted_at IS NULL
                 AND client_id = ?
                 AND status IN ('sent', 'accepted')
                 AND ABS(total - ?) < 0.01
                 ORDER BY issue_date DESC
                 LIMIT 1",
                [$companyId, $clientId, $tx->getAmount()]
            );
            if ($row) {
                return $this->em->find(ProformaInvoice::class, $row['id']);
            }
        }

        return null;
    }

    private function findClientByName(Company $company, string $name): ?Client
    {
        $conn = $this->em->getConnection();
        $companyId = $company->getId()->toRfc4122();

        // Exact name match (case-insensitive)
        $row = $conn->fetchAssociative(
            'SELECT id FROM client WHERE company_id = ? AND deleted_at IS NULL AND LOWER(name) = LOWER(?) LIMIT 1',
            [$companyId, $name]
        );

        if ($row) {
            return $this->em->find(Client::class, $row['id']);
        }

        // Partial name match (for shortened names)
        $row = $conn->fetchAssociative(
            'SELECT id FROM client WHERE company_id = ? AND deleted_at IS NULL AND (LOWER(name) LIKE LOWER(?) OR LOWER(?) LIKE CONCAT(\'%\', LOWER(name), \'%\')) LIMIT 1',
            [$companyId, '%' . $name . '%', $name]
        );

        if ($row) {
            return $this->em->find(Client::class, $row['id']);
        }

        return null;
    }

    private function findInvoiceByAwb(Company $company, string $awb): ?Invoice
    {
        $conn = $this->em->getConnection();
        $companyId = $company->getId()->toRfc4122();

        $row = $conn->fetchAssociative(
            "SELECT id FROM invoice
             WHERE company_id = ? AND deleted_at IS NULL AND direction = 'outgoing'
             AND order_number = ?
             LIMIT 1",
            [$companyId, $awb]
        );

        if ($row) {
            return $this->em->find(Invoice::class, $row['id']);
        }

        return null;
    }

    private function findInvoiceByNumber(Company $company, string $number): ?Invoice
    {
        $conn = $this->em->getConnection();
        $companyId = $company->getId()->toRfc4122();

        $row = $conn->fetchAssociative(
            "SELECT id FROM invoice
             WHERE company_id = ? AND deleted_at IS NULL AND direction = 'outgoing'
             AND (number = ? OR number LIKE ?)
             LIMIT 1",
            [$companyId, $number, '%' . $number]
        );

        if ($row) {
            return $this->em->find(Invoice::class, $row['id']);
        }

        return null;
    }

    private function findInvoiceByClientAndAmount(Company $company, Client $client, string $amount): ?Invoice
    {
        $conn = $this->em->getConnection();
        $companyId = $company->getId()->toRfc4122();
        $clientId = $client->getId()->toRfc4122();

        // Exact balance match
        $row = $conn->fetchAssociative(
            "SELECT id FROM invoice
             WHERE company_id = ? AND deleted_at IS NULL AND direction = 'outgoing'
             AND client_id = ?
             AND paid_at IS NULL
             AND status NOT IN ('draft', 'cancelled')
             AND ABS((total - amount_paid) - ?) < 0.01
             ORDER BY issue_date DESC
             LIMIT 1",
            [$companyId, $clientId, $amount]
        );

        if ($row) {
            return $this->em->find(Invoice::class, $row['id']);
        }

        // Within 5% tolerance
        $tolerance = bcmul($amount, '0.05', 2);
        $row = $conn->fetchAssociative(
            "SELECT id FROM invoice
             WHERE company_id = ? AND deleted_at IS NULL AND direction = 'outgoing'
             AND client_id = ?
             AND paid_at IS NULL
             AND status NOT IN ('draft', 'cancelled')
             AND ABS((total - amount_paid) - ?) <= ?
             ORDER BY ABS((total - amount_paid) - ?) ASC
             LIMIT 1",
            [$companyId, $clientId, $amount, $tolerance, $amount]
        );

        if ($row) {
            return $this->em->find(Invoice::class, $row['id']);
        }

        return null;
    }

    private function findInvoiceByReceiverNameAndAmount(Company $company, string $name, string $amount): ?Invoice
    {
        $conn = $this->em->getConnection();
        $companyId = $company->getId()->toRfc4122();

        $row = $conn->fetchAssociative(
            "SELECT id FROM invoice
             WHERE company_id = ? AND deleted_at IS NULL AND direction = 'outgoing'
             AND LOWER(receiver_name) = LOWER(?)
             AND paid_at IS NULL
             AND status NOT IN ('draft', 'cancelled')
             AND ABS((total - amount_paid) - ?) < 0.01
             ORDER BY issue_date DESC
             LIMIT 1",
            [$companyId, $name, $amount]
        );

        if ($row) {
            return $this->em->find(Invoice::class, $row['id']);
        }

        return null;
    }
}
