<?php

namespace App\Manager;

use App\Entity\Company;
use App\Entity\Invoice;
use App\Entity\Receipt;
use App\Entity\ReceiptLine;
use App\Entity\User;
use App\Enum\ReceiptStatus;
use App\Manager\Trait\DocumentCalculationTrait;
use App\Repository\ClientRepository;
use App\Repository\DocumentSeriesRepository;
use App\Repository\ReceiptRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

class ReceiptManager
{
    use DocumentCalculationTrait;

    public function __construct(
        private readonly ReceiptRepository $receiptRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly DocumentSeriesRepository $documentSeriesRepository,
        private readonly ClientRepository $clientRepository,
        private readonly InvoiceManager $invoiceManager,
    ) {}

    public function find(string $uuid): ?Receipt
    {
        return $this->receiptRepository->findWithDetails($uuid);
    }

    public function listByCompany(Company $company, array $filters = [], int $page = 1, int $limit = 20): array
    {
        return $this->receiptRepository->findByCompanyPaginated($company, $filters, $page, $limit);
    }

    public function create(Company $company, array $data, User $user): Receipt
    {
        $receipt = new Receipt();
        $receipt->setCompany($company);
        $receipt->setStatus(ReceiptStatus::DRAFT);

        // Resolve client (optional for B2C)
        if (!empty($data['clientId'])) {
            $client = $this->clientRepository->find(Uuid::fromString($data['clientId']));
            if ($client) {
                $receipt->setClient($client);
            }
        }

        // Scalar fields
        if (isset($data['issueDate'])) {
            $receipt->setIssueDate(new \DateTime($data['issueDate']));
        }
        $receipt->setCurrency($data['currency'] ?? $company->getDefaultCurrency());
        $receipt->setNotes($data['notes'] ?? null);
        $receipt->setMentions($data['mentions'] ?? null);
        $receipt->setInternalNote($data['internalNote'] ?? null);
        $receipt->setProjectReference($data['projectReference'] ?? null);
        $receipt->setIssuerName($data['issuerName'] ?? null);
        $receipt->setIssuerId($data['issuerId'] ?? null);
        $receipt->setSalesAgent($data['salesAgent'] ?? null);
        $receipt->setExchangeRate($data['exchangeRate'] ?? null);

        // Receipt-specific fields
        $receipt->setPaymentMethod($data['paymentMethod'] ?? null);
        $receipt->setCashPayment($data['cashPayment'] ?? null);
        $receipt->setCardPayment($data['cardPayment'] ?? null);
        $receipt->setOtherPayment($data['otherPayment'] ?? null);
        $receipt->setCashRegisterName($data['cashRegisterName'] ?? null);
        $receipt->setFiscalNumber($data['fiscalNumber'] ?? null);
        $receipt->setCustomerName($data['customerName'] ?? null);
        $receipt->setCustomerCif($data['customerCif'] ?? null);

        // Document series
        if (!empty($data['documentSeriesId'])) {
            $series = $this->documentSeriesRepository->find(Uuid::fromString($data['documentSeriesId']));
            if ($series && $series->getCompany()?->getId()->equals($company->getId())) {
                $receipt->setDocumentSeries($series);
            }
        }
        if (!$receipt->getDocumentSeries()) {
            $defaultSeries = $this->documentSeriesRepository->findDefaultByType($company, 'receipt');
            if ($defaultSeries) {
                $receipt->setDocumentSeries($defaultSeries);
            }
        }

        // Draft receipts get a temporary number
        $receipt->setNumber('BON-' . substr(Uuid::v7()->toRfc4122(), 0, 8));

        // Create lines
        $this->setLines($receipt, $data['lines'] ?? []);

        // Recalculate totals
        $this->recalculateTotals($receipt);

        $this->entityManager->persist($receipt);
        $this->entityManager->flush();

        return $receipt;
    }

    public function update(Receipt $receipt, array $data, User $user): Receipt
    {
        if (!$receipt->isEditable()) {
            throw new \DomainException('Bonul fiscal nu poate fi editat.');
        }

        // Update scalar fields
        if (isset($data['issueDate'])) {
            $receipt->setIssueDate(new \DateTime($data['issueDate']));
        }
        if (isset($data['currency'])) {
            $receipt->setCurrency($data['currency']);
        }
        if (array_key_exists('notes', $data)) {
            $receipt->setNotes($data['notes']);
        }
        if (array_key_exists('mentions', $data)) {
            $receipt->setMentions($data['mentions']);
        }
        if (array_key_exists('internalNote', $data)) {
            $receipt->setInternalNote($data['internalNote']);
        }
        if (array_key_exists('projectReference', $data)) {
            $receipt->setProjectReference($data['projectReference']);
        }
        if (array_key_exists('issuerName', $data)) {
            $receipt->setIssuerName($data['issuerName']);
        }
        if (array_key_exists('issuerId', $data)) {
            $receipt->setIssuerId($data['issuerId']);
        }
        if (array_key_exists('salesAgent', $data)) {
            $receipt->setSalesAgent($data['salesAgent']);
        }
        if (array_key_exists('exchangeRate', $data)) {
            $receipt->setExchangeRate($data['exchangeRate']);
        }

        // Receipt-specific fields
        if (array_key_exists('paymentMethod', $data)) {
            $receipt->setPaymentMethod($data['paymentMethod']);
        }
        if (array_key_exists('cashPayment', $data)) {
            $receipt->setCashPayment($data['cashPayment']);
        }
        if (array_key_exists('cardPayment', $data)) {
            $receipt->setCardPayment($data['cardPayment']);
        }
        if (array_key_exists('otherPayment', $data)) {
            $receipt->setOtherPayment($data['otherPayment']);
        }
        if (array_key_exists('cashRegisterName', $data)) {
            $receipt->setCashRegisterName($data['cashRegisterName']);
        }
        if (array_key_exists('fiscalNumber', $data)) {
            $receipt->setFiscalNumber($data['fiscalNumber']);
        }
        if (array_key_exists('customerName', $data)) {
            $receipt->setCustomerName($data['customerName']);
        }
        if (array_key_exists('customerCif', $data)) {
            $receipt->setCustomerCif($data['customerCif']);
        }

        // Update document series
        if (isset($data['documentSeriesId'])) {
            if ($data['documentSeriesId']) {
                $series = $this->documentSeriesRepository->find(Uuid::fromString($data['documentSeriesId']));
                if ($series && $series->getCompany()?->getId()->equals($receipt->getCompany()->getId())) {
                    $receipt->setDocumentSeries($series);
                }
            } else {
                $receipt->setDocumentSeries(null);
            }
        }

        // Update client
        if (isset($data['clientId'])) {
            $client = $this->clientRepository->find(Uuid::fromString($data['clientId']));
            if ($client) {
                $receipt->setClient($client);
            }
        }

        // Replace lines
        if (isset($data['lines'])) {
            $receipt->clearLines();
            $this->entityManager->flush();
            $this->setLines($receipt, $data['lines']);
        }

        // Recalculate totals
        $this->recalculateTotals($receipt);

        $this->entityManager->flush();

        return $receipt;
    }

    public function delete(Receipt $receipt): void
    {
        if (!$receipt->isDeletable()) {
            throw new \DomainException('Bonul fiscal nu poate fi sters.');
        }

        $receipt->setDeletedAt(new \DateTimeImmutable());
        $this->entityManager->flush();
    }

    public function issue(Receipt $receipt, User $user): void
    {
        if ($receipt->getStatus() !== ReceiptStatus::DRAFT) {
            throw new \DomainException('Doar bonurile ciorna pot fi emise.');
        }

        // Auto-assign default series if none set
        $series = $receipt->getDocumentSeries();
        if (!$series) {
            $series = $this->documentSeriesRepository->findDefaultByType(
                $receipt->getCompany(),
                'receipt'
            );
            if ($series) {
                $receipt->setDocumentSeries($series);
            }
        }

        // Assign final number from DocumentSeries with pessimistic lock
        if ($series) {
            $this->entityManager->wrapInTransaction(function () use ($series, $receipt) {
                $this->entityManager->lock($series, LockMode::PESSIMISTIC_WRITE);
                $this->entityManager->refresh($series);
                $newNumber = $series->getCurrentNumber() + 1;
                $series->setCurrentNumber($newNumber);
                $receipt->setNumber($series->getPrefix() . str_pad((string) $newNumber, 4, '0', STR_PAD_LEFT));
            });
        }

        $receipt->setStatus(ReceiptStatus::ISSUED);
        $receipt->setIssuedAt(new \DateTimeImmutable());
        $this->entityManager->flush();
    }

    public function cancel(Receipt $receipt): void
    {
        if ($receipt->getStatus() === ReceiptStatus::INVOICED) {
            throw new \DomainException('Bonul facturat nu poate fi anulat.');
        }

        if ($receipt->getStatus() === ReceiptStatus::CANCELLED) {
            throw new \DomainException('Bonul este deja anulat.');
        }

        $receipt->setStatus(ReceiptStatus::CANCELLED);
        $receipt->setCancelledAt(new \DateTimeImmutable());
        $this->entityManager->flush();
    }

    public function restore(Receipt $receipt): void
    {
        if ($receipt->getStatus() !== ReceiptStatus::CANCELLED) {
            throw new \DomainException('Doar bonurile anulate pot fi restaurate.');
        }

        $receipt->setStatus(ReceiptStatus::DRAFT);
        $receipt->setCancelledAt(null);
        $this->entityManager->flush();
    }

    public function convertToInvoice(Receipt $receipt, Company $company, User $user): Invoice
    {
        if ($receipt->getStatus() !== ReceiptStatus::ISSUED) {
            throw new \DomainException('Doar bonurile emise pot fi convertite in factura.');
        }

        // Build invoice data from receipt
        $linesData = [];
        foreach ($receipt->getLines() as $line) {
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

        // Find default invoice series
        $defaultInvoiceSeries = $this->documentSeriesRepository->findDefaultByType($company, 'invoice');

        $invoiceData = [
            'documentType' => 'invoice',
            'issueDate' => (new \DateTime())->format('Y-m-d'),
            'currency' => $receipt->getCurrency(),
            'notes' => $receipt->getNotes(),
            'projectReference' => $receipt->getProjectReference(),
            'mentions' => $receipt->getMentions(),
            'internalNote' => $receipt->getInternalNote(),
            'salesAgent' => $receipt->getSalesAgent(),
            'issuerName' => $receipt->getIssuerName(),
            'issuerId' => $receipt->getIssuerId(),
            'exchangeRate' => $receipt->getExchangeRate(),
            'clientId' => $receipt->getClient() ? (string) $receipt->getClient()->getId() : null,
            'documentSeriesId' => $defaultInvoiceSeries ? (string) $defaultInvoiceSeries->getId() : null,
            'lines' => $linesData,
        ];

        $invoice = $this->invoiceManager->create($company, $invoiceData, $user);

        // Mark receipt as invoiced
        $receipt->setStatus(ReceiptStatus::INVOICED);
        $receipt->setConvertedInvoice($invoice);
        $this->entityManager->flush();

        return $invoice;
    }

    private function setLines(Receipt $receipt, array $linesData): void
    {
        foreach ($linesData as $i => $lineData) {
            $line = new ReceiptLine();
            $this->populateLineFields($line, $lineData, $i + 1);
            $receipt->addLine($line);
        }
    }

    private function recalculateTotals(Receipt $receipt): void
    {
        $this->recalculateStoredTotals($receipt);
    }
}
