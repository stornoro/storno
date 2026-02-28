<?php

namespace App\Service\Import\Persister;

use App\Entity\Company;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\Payment;
use App\Enum\DocumentStatus;
use App\Enum\DocumentType;
use App\Enum\InvoiceDirection;
use App\Repository\InvoiceRepository;
use App\Service\Import\ImportResult;
use Doctrine\ORM\EntityManagerInterface;

class InvoicePersister implements EntityPersisterInterface
{
    private const BATCH_SIZE = 20;
    private int $batchCount = 0;

    /**
     * In-memory dedup cache keyed by the composite dedup key.
     * Value is the Invoice entity so multi-line formats can append lines.
     *
     * @var array<string, Invoice>
     */
    private array $pendingCache = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly InvoiceRepository $invoiceRepository,
    ) {}

    public function supports(string $importType): bool
    {
        return in_array($importType, ['invoices', 'invoices_issued', 'invoices_received'], true);
    }

    public function persist(array $mappedData, Company $company, ImportResult $result): void
    {
        $number = $mappedData['number'] ?? null;
        if (empty($number)) {
            return;
        }

        $senderCif   = !empty($mappedData['senderCif'])   ? trim($mappedData['senderCif'])   : null;
        $receiverCif = !empty($mappedData['receiverCif']) ? trim($mappedData['receiverCif']) : null;
        $direction   = $mappedData['direction'] ?? null;

        // Build composite dedup key: number + both CIFs + direction
        $dedupKey = implode(':', [
            $company->getId()->toRfc4122(),
            mb_strtolower($number),
            $senderCif   ?? '',
            $receiverCif ?? '',
            $direction   ?? '',
        ]);

        // In-memory check: within-batch duplicate → append lines (multi-line formats)
        if (isset($this->pendingCache[$dedupKey])) {
            $existingInvoice = $this->pendingCache[$dedupKey];
            $lines = $mappedData['lines'] ?? [];
            $currentPosition = $existingInvoice->getLines()->count();
            foreach ($lines as $i => $lineData) {
                $line = $this->buildInvoiceLine($lineData, $currentPosition + $i);
                $existingInvoice->addLine($line);
            }

            // Accumulate invoice-level totals
            if (isset($mappedData['subtotal']) && $mappedData['subtotal'] !== '') {
                $existingSubtotal = (float) ($existingInvoice->getSubtotal() ?? '0');
                $existingInvoice->setSubtotal(number_format($existingSubtotal + (float) $mappedData['subtotal'], 2, '.', ''));
            }
            if (isset($mappedData['vatTotal']) && $mappedData['vatTotal'] !== '') {
                $existingVat = (float) ($existingInvoice->getVatTotal() ?? '0');
                $existingInvoice->setVatTotal(number_format($existingVat + (float) $mappedData['vatTotal'], 2, '.', ''));
            }
            if (isset($mappedData['total']) && $mappedData['total'] !== '') {
                $existingTotal = (float) ($existingInvoice->getTotal() ?? '0');
                $existingInvoice->setTotal(number_format($existingTotal + (float) $mappedData['total'], 2, '.', ''));
            }

            return; // don't increment any counter
        }

        // Database check: match by number + senderCif/receiverCif + direction
        $existing = $this->findExistingInvoice($company, $number, $senderCif, $receiverCif, $direction);
        if ($existing !== null) {
            // Invoices are never updated on re-import — skip to preserve data integrity
            $this->pendingCache[$dedupKey] = $existing;
            $result->incrementSkipped();
            return;
        }

        // Create the invoice
        $invoice = $this->buildInvoice($mappedData, $company);
        $this->entityManager->persist($invoice);

        // Create line items
        $lines = $mappedData['lines'] ?? [];
        foreach ($lines as $position => $lineData) {
            $line = $this->buildInvoiceLine($lineData, $position);
            $invoice->addLine($line);
        }

        // Mark as paid: create a Payment if requested via import options
        $importOptions = $mappedData['_importOptions'] ?? [];
        if (!empty($importOptions['markAsPaid'])) {
            $payment = new Payment();
            $payment->setCompany($company);
            $payment->setInvoice($invoice);
            $payment->setAmount($invoice->getTotal() ?? '0.00');
            $payment->setCurrency($invoice->getCurrency() ?? 'RON');
            $payment->setPaymentDate($invoice->getIssueDate() ?? new \DateTime());
            $payment->setPaymentMethod($invoice->getPaymentMethod() ?? 'bank_transfer');
            $payment->setReference('Import automat');
            $payment->setIsReconciled(true);
            $this->entityManager->persist($payment);
        }

        $this->pendingCache[$dedupKey] = $invoice;
        $result->incrementCreated();

        $this->batchCount++;
        if ($this->batchCount >= self::BATCH_SIZE) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        $this->entityManager->flush();
        $this->batchCount = 0;
    }

    public function reset(): void
    {
        $this->pendingCache = [];
        $this->batchCount = 0;
    }

    private function findExistingInvoice(
        Company $company,
        string $number,
        ?string $senderCif,
        ?string $receiverCif,
        ?string $direction,
    ): ?Invoice {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('i')
            ->from(Invoice::class, 'i')
            ->where('i.company = :company')
            ->andWhere('i.number = :number')
            ->andWhere('i.deletedAt IS NULL')
            ->setParameter('company', $company)
            ->setParameter('number', $number);

        if ($senderCif !== null) {
            $qb->andWhere('i.senderCif = :senderCif')
                ->setParameter('senderCif', $senderCif);
        }

        if ($receiverCif !== null) {
            $qb->andWhere('i.receiverCif = :receiverCif')
                ->setParameter('receiverCif', $receiverCif);
        }

        if ($direction !== null) {
            try {
                $directionEnum = InvoiceDirection::from($direction);
                $qb->andWhere('i.direction = :direction')
                    ->setParameter('direction', $directionEnum);
            } catch (\ValueError) {
                // Unknown direction string — ignore filter
            }
        }

        return $qb->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }

    private function buildInvoice(array $data, Company $company): Invoice
    {
        $invoice = new Invoice();
        $invoice->setCompany($company);

        // Required: number
        $invoice->setNumber($data['number']);

        // Document type — default to invoice
        $documentType = DocumentType::INVOICE;
        if (!empty($data['documentType'])) {
            try {
                $documentType = DocumentType::from($data['documentType']);
            } catch (\ValueError) {
                // Unknown type — keep default
            }
        }
        $invoice->setDocumentType($documentType);

        // Status — imported invoices are set to SYNCED (read-only, from external source)
        $status = DocumentStatus::SYNCED;
        if (!empty($data['status'])) {
            try {
                $status = DocumentStatus::from($data['status']);
            } catch (\ValueError) {
                // Unknown status — keep SYNCED
            }
        }
        $invoice->setStatus($status);

        // Direction
        if (!empty($data['direction'])) {
            try {
                $invoice->setDirection(InvoiceDirection::from($data['direction']));
            } catch (\ValueError) {
                // Unknown direction — leave null
            }
        }

        // CIF fields
        if (!empty($data['senderCif'])) {
            $invoice->setSenderCif(trim($data['senderCif']));
        }
        if (!empty($data['receiverCif'])) {
            $invoice->setReceiverCif(trim($data['receiverCif']));
        }
        if (!empty($data['senderName'])) {
            $invoice->setSenderName($data['senderName']);
        }
        if (!empty($data['receiverName'])) {
            $invoice->setReceiverName($data['receiverName']);
        }

        // Financials
        if (isset($data['subtotal']) && $data['subtotal'] !== '') {
            $invoice->setSubtotal(number_format((float) $data['subtotal'], 2, '.', ''));
        }
        if (isset($data['vatTotal']) && $data['vatTotal'] !== '') {
            $invoice->setVatTotal(number_format((float) $data['vatTotal'], 2, '.', ''));
        }
        if (isset($data['total']) && $data['total'] !== '') {
            $invoice->setTotal(number_format((float) $data['total'], 2, '.', ''));
        }
        if (isset($data['discount']) && $data['discount'] !== '') {
            $invoice->setDiscount(number_format((float) $data['discount'], 2, '.', ''));
        }

        // Currency
        if (!empty($data['currency'])) {
            $invoice->setCurrency(strtoupper(trim($data['currency'])));
        }

        // Dates
        if (!empty($data['issueDate'])) {
            try {
                $invoice->setIssueDate(new \DateTime($data['issueDate']));
            } catch (\Exception) {
                // Keep default (today)
            }
        }
        if (!empty($data['dueDate'])) {
            try {
                $invoice->setDueDate(new \DateTime($data['dueDate']));
            } catch (\Exception) {
                // Leave null
            }
        }

        // Optional text fields
        if (!empty($data['notes'])) {
            $invoice->setNotes($data['notes']);
        }
        if (!empty($data['paymentTerms'])) {
            $invoice->setPaymentTerms($data['paymentTerms']);
        }
        if (!empty($data['invoiceTypeCode'])) {
            $invoice->setInvoiceTypeCode($data['invoiceTypeCode']);
        }
        if (!empty($data['exchangeRate'])) {
            $invoice->setExchangeRate(number_format((float) $data['exchangeRate'], 4, '.', ''));
        }
        if (!empty($data['orderNumber'])) {
            $invoice->setOrderNumber($data['orderNumber']);
        }
        if (!empty($data['contractNumber'])) {
            $invoice->setContractNumber($data['contractNumber']);
        }
        if (!empty($data['paymentMethod'])) {
            $invoice->setPaymentMethod($data['paymentMethod']);
        }

        // Build a stable idempotency key for the import source
        $source = $data['_source'] ?? 'generic';
        $idempotencyKey = 'import:' . $source . ':' . $company->getId()->toRfc4122() . ':' . $data['number'];
        if (!empty($data['senderCif'])) {
            $idempotencyKey .= ':' . $data['senderCif'];
        }
        $invoice->setIdempotencyKey(hash('sha256', $idempotencyKey));

        return $invoice;
    }

    private function buildInvoiceLine(array $lineData, int $position): InvoiceLine
    {
        $line = new InvoiceLine();
        $line->setPosition($position);

        // Description is required on the entity (length: 500)
        $line->setDescription(!empty($lineData['description']) ? mb_substr($lineData['description'], 0, 500) : '-');

        if (isset($lineData['quantity']) && $lineData['quantity'] !== '') {
            $line->setQuantity(number_format((float) $lineData['quantity'], 4, '.', ''));
        }

        if (!empty($lineData['unitOfMeasure'])) {
            $line->setUnitOfMeasure($lineData['unitOfMeasure']);
        }

        if (isset($lineData['unitPrice']) && $lineData['unitPrice'] !== '') {
            $line->setUnitPrice(number_format((float) $lineData['unitPrice'], 2, '.', ''));
        }

        if (isset($lineData['vatRate']) && $lineData['vatRate'] !== '') {
            $line->setVatRate((string) $lineData['vatRate']);
        }

        if (!empty($lineData['vatCategoryCode'])) {
            $line->setVatCategoryCode($lineData['vatCategoryCode']);
        }

        if (isset($lineData['vatAmount']) && $lineData['vatAmount'] !== '') {
            $line->setVatAmount(number_format((float) $lineData['vatAmount'], 2, '.', ''));
        }

        if (isset($lineData['lineTotal']) && $lineData['lineTotal'] !== '') {
            $line->setLineTotal(number_format((float) $lineData['lineTotal'], 2, '.', ''));
        }

        if (isset($lineData['discount']) && $lineData['discount'] !== '') {
            $line->setDiscount(number_format((float) $lineData['discount'], 2, '.', ''));
        }

        if (isset($lineData['discountPercent']) && $lineData['discountPercent'] !== '') {
            $line->setDiscountPercent(number_format((float) $lineData['discountPercent'], 2, '.', ''));
        }

        if (isset($lineData['vatIncluded'])) {
            $line->setVatIncluded((bool) $lineData['vatIncluded']);
        }

        if (!empty($lineData['productCode'])) {
            $line->setProductCode($lineData['productCode']);
        }

        return $line;
    }
}
