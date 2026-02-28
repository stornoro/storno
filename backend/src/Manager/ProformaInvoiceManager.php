<?php

namespace App\Manager;

use App\Entity\Company;
use App\Entity\ProformaInvoice;
use App\Entity\ProformaInvoiceLine;
use App\Entity\Invoice;
use App\Entity\User;
use App\Enum\ProformaStatus;
use App\Manager\Trait\DocumentCalculationTrait;
use App\Repository\ClientRepository;
use App\Repository\DocumentSeriesRepository;
use App\Repository\ProformaInvoiceRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

class ProformaInvoiceManager
{
    use DocumentCalculationTrait;
    public function __construct(
        private readonly ProformaInvoiceRepository $proformaInvoiceRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly DocumentSeriesRepository $documentSeriesRepository,
        private readonly ClientRepository $clientRepository,
        private readonly InvoiceManager $invoiceManager,
    ) {}

    public function find(string $uuid): ?ProformaInvoice
    {
        return $this->proformaInvoiceRepository->findWithDetails($uuid);
    }

    public function listByCompany(Company $company, array $filters = [], int $page = 1, int $limit = 20): array
    {
        return $this->proformaInvoiceRepository->findByCompanyPaginated($company, $filters, $page, $limit);
    }

    public function create(Company $company, array $data, User $user): ProformaInvoice
    {
        $proforma = new ProformaInvoice();
        $proforma->setCompany($company);
        $proforma->setStatus(ProformaStatus::DRAFT);

        // Resolve client
        if (!empty($data['clientId'])) {
            $client = $this->clientRepository->find(Uuid::fromString($data['clientId']));
            if ($client) {
                $proforma->setClient($client);
            }
        }

        // Scalar fields
        if (isset($data['issueDate'])) {
            $proforma->setIssueDate(new \DateTime($data['issueDate']));
        }
        if (isset($data['dueDate'])) {
            $proforma->setDueDate(new \DateTime($data['dueDate']));
        }
        if (isset($data['validUntil'])) {
            $proforma->setValidUntil(new \DateTime($data['validUntil']));
        }
        $proforma->setCurrency($data['currency'] ?? $company->getDefaultCurrency());
        $proforma->setNotes($data['notes'] ?? null);
        $proforma->setPaymentTerms($data['paymentTerms'] ?? null);
        $proforma->setInvoiceTypeCode($data['invoiceTypeCode'] ?? null);
        $proforma->setDeliveryLocation($data['deliveryLocation'] ?? null);
        $proforma->setProjectReference($data['projectReference'] ?? null);

        // New fields
        $proforma->setOrderNumber($data['orderNumber'] ?? null);
        $proforma->setContractNumber($data['contractNumber'] ?? null);
        $proforma->setIssuerName($data['issuerName'] ?? null);
        $proforma->setIssuerId($data['issuerId'] ?? null);
        $proforma->setMentions($data['mentions'] ?? null);
        $proforma->setInternalNote($data['internalNote'] ?? null);
        $proforma->setSalesAgent($data['salesAgent'] ?? null);

        if (isset($data['language'])) {
            $proforma->setLanguage($data['language']);
        }

        if (isset($data['exchangeRate'])) {
            $proforma->setExchangeRate($data['exchangeRate']);
        }

        // Auto-numbering from DocumentSeries with pessimistic lock
        if (!empty($data['documentSeriesId'])) {
            $series = $this->documentSeriesRepository->find(Uuid::fromString($data['documentSeriesId']));
            if ($series && $series->getCompany()?->getId()->equals($company->getId())) {
                $this->entityManager->wrapInTransaction(function () use ($series, $proforma) {
                    $this->entityManager->lock($series, LockMode::PESSIMISTIC_WRITE);
                    $this->entityManager->refresh($series);
                    $newNumber = $series->getCurrentNumber() + 1;
                    $series->setCurrentNumber($newNumber);
                    $proforma->setNumber($series->getPrefix() . str_pad((string) $newNumber, 4, '0', STR_PAD_LEFT));
                    $proforma->setDocumentSeries($series);
                });
            }
        }

        // If no number was assigned, generate a temporary one
        if (!$proforma->getNumber()) {
            $proforma->setNumber('PRO-' . substr(Uuid::v7()->toRfc4122(), 0, 8));
        }

        // Create lines
        $this->setLines($proforma, $data['lines'] ?? []);

        // Recalculate totals
        $this->recalculateTotals($proforma);

        $this->entityManager->persist($proforma);
        $this->entityManager->flush();

        return $proforma;
    }

    public function update(ProformaInvoice $proforma, array $data, User $user): ProformaInvoice
    {
        if (!$proforma->isEditable()) {
            throw new \DomainException('Proforma nu poate fi editata.');
        }

        // Update scalar fields
        if (isset($data['issueDate'])) {
            $proforma->setIssueDate(new \DateTime($data['issueDate']));
        }
        if (isset($data['dueDate'])) {
            $proforma->setDueDate(new \DateTime($data['dueDate']));
        }
        if (isset($data['validUntil'])) {
            $proforma->setValidUntil(new \DateTime($data['validUntil']));
        }
        if (isset($data['currency'])) {
            $proforma->setCurrency($data['currency']);
        }
        if (array_key_exists('notes', $data)) {
            $proforma->setNotes($data['notes']);
        }
        if (array_key_exists('paymentTerms', $data)) {
            $proforma->setPaymentTerms($data['paymentTerms']);
        }
        if (array_key_exists('invoiceTypeCode', $data)) {
            $proforma->setInvoiceTypeCode($data['invoiceTypeCode']);
        }
        if (array_key_exists('deliveryLocation', $data)) {
            $proforma->setDeliveryLocation($data['deliveryLocation']);
        }
        if (array_key_exists('projectReference', $data)) {
            $proforma->setProjectReference($data['projectReference']);
        }

        // Update new fields
        if (array_key_exists('orderNumber', $data)) {
            $proforma->setOrderNumber($data['orderNumber']);
        }
        if (array_key_exists('contractNumber', $data)) {
            $proforma->setContractNumber($data['contractNumber']);
        }
        if (array_key_exists('issuerName', $data)) {
            $proforma->setIssuerName($data['issuerName']);
        }
        if (array_key_exists('issuerId', $data)) {
            $proforma->setIssuerId($data['issuerId']);
        }
        if (array_key_exists('mentions', $data)) {
            $proforma->setMentions($data['mentions']);
        }
        if (array_key_exists('internalNote', $data)) {
            $proforma->setInternalNote($data['internalNote']);
        }
        if (array_key_exists('salesAgent', $data)) {
            $proforma->setSalesAgent($data['salesAgent']);
        }
        if (isset($data['language'])) {
            $proforma->setLanguage($data['language']);
        }
        if (isset($data['exchangeRate'])) {
            $proforma->setExchangeRate($data['exchangeRate']);
        }

        // Update client
        if (isset($data['clientId'])) {
            $client = $this->clientRepository->find(Uuid::fromString($data['clientId']));
            if ($client) {
                $proforma->setClient($client);
            }
        }

        // Replace lines
        if (isset($data['lines'])) {
            $proforma->clearLines();
            $this->entityManager->flush();
            $this->setLines($proforma, $data['lines']);
        }

        // Recalculate totals
        $this->recalculateTotals($proforma);

        $this->entityManager->flush();

        return $proforma;
    }

    public function delete(ProformaInvoice $proforma): void
    {
        if (!$proforma->isDeletable()) {
            throw new \DomainException('Proforma nu poate fi stearsa.');
        }

        $proforma->setDeletedAt(new \DateTimeImmutable());
        $this->entityManager->flush();
    }

    public function send(ProformaInvoice $proforma): void
    {
        if ($proforma->getStatus() !== ProformaStatus::DRAFT) {
            throw new \DomainException('Doar proformele ciorna pot fi trimise.');
        }

        $proforma->setStatus(ProformaStatus::SENT);
        $proforma->setSentAt(new \DateTimeImmutable());
        $this->entityManager->flush();
    }

    public function accept(ProformaInvoice $proforma): void
    {
        if ($proforma->getStatus() !== ProformaStatus::SENT) {
            throw new \DomainException('Doar proformele trimise pot fi acceptate.');
        }

        $proforma->setStatus(ProformaStatus::ACCEPTED);
        $proforma->setAcceptedAt(new \DateTimeImmutable());
        $this->entityManager->flush();
    }

    public function reject(ProformaInvoice $proforma): void
    {
        if ($proforma->getStatus() !== ProformaStatus::SENT) {
            throw new \DomainException('Doar proformele trimise pot fi respinse.');
        }

        $proforma->setStatus(ProformaStatus::REJECTED);
        $proforma->setRejectedAt(new \DateTimeImmutable());
        $this->entityManager->flush();
    }

    public function cancel(ProformaInvoice $proforma): void
    {
        if ($proforma->getStatus() === ProformaStatus::CONVERTED) {
            throw new \DomainException('Proforma convertita nu poate fi anulata.');
        }

        if ($proforma->getStatus() === ProformaStatus::CANCELLED) {
            throw new \DomainException('Proforma este deja anulata.');
        }

        $proforma->setStatus(ProformaStatus::CANCELLED);
        $proforma->setCancelledAt(new \DateTimeImmutable());
        $this->entityManager->flush();
    }

    public function expire(ProformaInvoice $proforma): void
    {
        if (!in_array($proforma->getStatus(), [ProformaStatus::SENT, ProformaStatus::ACCEPTED], true)) {
            throw new \DomainException('Doar proformele trimise sau acceptate pot expira.');
        }

        $proforma->setStatus(ProformaStatus::EXPIRED);
        $proforma->setExpiredAt(new \DateTimeImmutable());
        $this->entityManager->flush();
    }

    public function convertToInvoice(ProformaInvoice $proforma, Company $company, User $user): Invoice
    {
        if (!in_array($proforma->getStatus(), [ProformaStatus::ACCEPTED, ProformaStatus::SENT], true)) {
            throw new \DomainException('Doar proformele trimise sau acceptate pot fi convertite.');
        }

        // Build invoice data from proforma
        $linesData = [];
        foreach ($proforma->getLines() as $line) {
            $linesData[] = [
                'description' => $line->getDescription(),
                'quantity' => $line->getQuantity(),
                'unitOfMeasure' => $line->getUnitOfMeasure(),
                'unitPrice' => $line->getUnitPrice(),
                'vatRate' => $line->getVatRate(),
                'vatCategoryCode' => $line->getVatCategoryCode(),
                'discount' => $line->getDiscount(),
                'discountPercent' => $line->getDiscountPercent(),
                'vatIncluded' => $line->isVatIncluded(),
                'productCode' => $line->getProductCode(),
            ];
        }

        // Find default invoice series for auto-numbering on issue
        $defaultSeries = $this->documentSeriesRepository->findDefaultByType($company, 'invoice');

        $invoiceData = [
            'documentType' => 'invoice',
            'issueDate' => (new \DateTime())->format('Y-m-d'),
            'dueDate' => $proforma->getDueDate()?->format('Y-m-d'),
            'currency' => $proforma->getCurrency(),
            'notes' => $proforma->getNotes(),
            'paymentTerms' => $proforma->getPaymentTerms(),
            'invoiceTypeCode' => $proforma->getInvoiceTypeCode(),
            'deliveryLocation' => $proforma->getDeliveryLocation(),
            'projectReference' => $proforma->getProjectReference(),
            'clientId' => $proforma->getClient() ? (string) $proforma->getClient()->getId() : null,
            'orderNumber' => $proforma->getOrderNumber(),
            'contractNumber' => $proforma->getContractNumber(),
            'issuerName' => $proforma->getIssuerName(),
            'issuerId' => $proforma->getIssuerId(),
            'mentions' => $proforma->getMentions(),
            'salesAgent' => $proforma->getSalesAgent(),
            'exchangeRate' => $proforma->getExchangeRate(),
            'language' => $proforma->getLanguage(),
            'documentSeriesId' => $defaultSeries ? (string) $defaultSeries->getId() : null,
            'lines' => $linesData,
        ];

        $invoice = $this->invoiceManager->create($company, $invoiceData, $user);

        // Mark proforma as converted
        $proforma->setStatus(ProformaStatus::CONVERTED);
        $proforma->setConvertedInvoice($invoice);
        $this->entityManager->flush();

        return $invoice;
    }

    private function setLines(ProformaInvoice $proforma, array $linesData): void
    {
        foreach ($linesData as $i => $lineData) {
            $line = new ProformaInvoiceLine();
            $this->populateLineFields($line, $lineData, $i + 1);
            $proforma->addLine($line);
        }
    }

    private function recalculateTotals(ProformaInvoice $proforma): void
    {
        $this->recalculateStoredTotals($proforma);
    }
}
