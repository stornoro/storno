<?php

namespace App\Service;

use App\Entity\DocumentEvent;
use App\Entity\RecurringInvoice;
use App\Enum\DocumentStatus;
use App\Enum\DocumentType;
use App\Manager\InvoiceManager;
use App\Manager\ProformaInvoiceManager;
use App\Repository\RecurringInvoiceRepository;
use App\Service\ExchangeRateService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class RecurringInvoiceProcessor
{
    private const ROMANIAN_MONTHS = [
        1 => 'ianuarie', 2 => 'februarie', 3 => 'martie',
        4 => 'aprilie', 5 => 'mai', 6 => 'iunie',
        7 => 'iulie', 8 => 'august', 9 => 'septembrie',
        10 => 'octombrie', 11 => 'noiembrie', 12 => 'decembrie',
    ];

    public function __construct(
        private readonly RecurringInvoiceRepository $recurringInvoiceRepository,
        private readonly InvoiceManager $invoiceManager,
        private readonly ProformaInvoiceManager $proformaInvoiceManager,
        private readonly EntityManagerInterface $entityManager,
        private readonly ExchangeRateService $exchangeRateService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Process all recurring invoices due on or before the given date.
     *
     * @return array{processed: int, errors: int, invoices: string[]}
     */
    public function processRecurringInvoices(\DateTimeInterface $date, int $limit = 100, bool $dryRun = false): array
    {
        $dueItems = $this->recurringInvoiceRepository->findDueForProcessing($date, $limit);

        $processed = 0;
        $errors = 0;
        $invoiceNumbers = [];

        foreach ($dueItems as $recurringInvoice) {
            try {
                $result = $this->processOne($recurringInvoice, $date, $dryRun);
                if ($result) {
                    $invoiceNumbers[] = $result['invoiceNumber'];
                }
                $processed++;
            } catch (\Throwable $e) {
                $errors++;
                $this->logger->error('Failed to process recurring invoice {id}: {error}', [
                    'id' => (string) $recurringInvoice->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'processed' => $processed,
            'errors' => $errors,
            'invoices' => $invoiceNumbers,
        ];
    }

    /**
     * Manually issue a single recurring invoice now.
     *
     * @return array{invoiceId: string, invoiceNumber: string}
     */
    public function issueNow(RecurringInvoice $ri, ?\App\Entity\User $user = null): array
    {
        $result = $this->processOne($ri, new \DateTimeImmutable(), false, $user);
        if (!$result) {
            throw new \RuntimeException('Failed to generate document from recurring template.');
        }

        return $result;
    }

    /**
     * @return array{invoiceId: string, invoiceNumber: string, documentType: string}|null
     */
    private function processOne(RecurringInvoice $ri, \DateTimeInterface $date, bool $dryRun, ?\App\Entity\User $user = null): ?array
    {
        $company = $ri->getCompany();
        if (!$company) {
            return null;
        }

        // Build document data from the recurring template — issue at current time, not scheduled date
        $issueDate = $date;
        $month = (int) $issueDate->format('n');
        $year = (int) $issueDate->format('Y');

        $documentData = [
            'documentType' => $ri->getDocumentType()->value,
            'currency' => $ri->getCurrency(),
            'issueDate' => $issueDate->format('Y-m-d'),
            'notes' => $ri->getNotes(), // replaced with template vars after rate notes are collected
            'paymentTerms' => $ri->getPaymentTerms(),
            'invoiceTypeCode' => $ri->getInvoiceTypeCode(),
        ];

        // Client
        if ($ri->getClient()) {
            $documentData['clientId'] = (string) $ri->getClient()->getId();
        }

        // Document series
        if ($ri->getDocumentSeries()) {
            $documentData['documentSeriesId'] = (string) $ri->getDocumentSeries()->getId();
        }

        // Due date calculation
        $dueDate = $this->calculateDueDate($ri, $issueDate);
        if ($dueDate) {
            $documentData['dueDate'] = $dueDate->format('Y-m-d');
        }

        // Lines with template variable replacement and per-line currency conversion
        $lines = [];
        $rateNotes = [];
        foreach ($ri->getLines() as $line) {
            $unitPrice = $line->getUnitPrice();
            $discount = $line->getDiscount();

            // Price rule: refresh product price if needed
            $priceRule = $line->getPriceRule();
            if ($priceRule === 'updated_product' && $line->getProduct()) {
                $this->entityManager->refresh($line->getProduct());
                $unitPrice = $line->getProduct()->getDefaultPrice();
            }

            // Per-line reference currency conversion (applies for bnr_rate / bnr_rate_markup price rules,
            // or legacy behaviour when referenceCurrency is set)
            $lineRefCurrency = $line->getReferenceCurrency();
            if ($lineRefCurrency && $ri->getCurrency() === 'RON') {
                $rate = $this->exchangeRateService->getRate($lineRefCurrency);
                if ($rate !== null) {
                    $markup = $line->getMarkupPercent() ? (float) $line->getMarkupPercent() : 0;
                    $multiplier = $rate * (1 + $markup / 100);
                    $unitPrice = number_format((float) $unitPrice * $multiplier, 2, '.', '');
                    $discount = number_format((float) $discount * $multiplier, 2, '.', '');

                    // Track for audit note (one per currency)
                    $noteKey = $lineRefCurrency;
                    if (!isset($rateNotes[$noteKey])) {
                        $rateNote = sprintf('Curs BNR %s: 1 %s = %s RON', $issueDate->format('d.m.Y'), $lineRefCurrency, number_format($rate, 4, '.', ''));
                        $rateNotes[$noteKey] = $rateNote;
                    }
                } else {
                    $this->logger->warning('Cannot resolve exchange rate for {currency}, using template prices as-is', [
                        'currency' => $lineRefCurrency,
                        'recurringInvoiceId' => (string) $ri->getId(),
                    ]);
                }
            }

            $lines[] = [
                'description' => $this->replaceTemplateVars($line->getDescription(), $month, $year),
                'quantity' => $line->getQuantity(),
                'unitOfMeasure' => $line->getUnitOfMeasure(),
                'unitPrice' => $unitPrice,
                'vatRate' => $line->getVatRate(),
                'vatCategoryCode' => $line->getVatCategoryCode(),
                'discount' => $discount,
                'discountPercent' => $line->getDiscountPercent(),
            ];
        }
        $documentData['lines'] = $lines;

        // Build exchange rate text for [[curs]] template variable
        $rateText = !empty($rateNotes) ? implode("\n", $rateNotes) : '';

        // Now replace template variables in notes (including [[curs]])
        $documentData['notes'] = $this->replaceTemplateVars($documentData['notes'], $month, $year, $rateText);

        if ($dryRun) {
            $this->advanceNextIssuanceDate($ri);
            return [
                'invoiceId' => '',
                'invoiceNumber' => '[DRY-RUN] ' . ($ri->getReference() ?? (string) $ri->getId()),
                'documentType' => $ri->getDocumentType()->value,
            ];
        }

        // Create the document based on documentType
        if ($ri->getDocumentType() === DocumentType::PROFORMA) {
            return $this->createProforma($ri, $company, $documentData, $issueDate, $user);
        }

        return $this->createInvoice($ri, $company, $documentData, $issueDate, $user);
    }

    /**
     * @return array{invoiceId: string, invoiceNumber: string, documentType: string}
     */
    private function createInvoice(RecurringInvoice $ri, \App\Entity\Company $company, array $documentData, \DateTimeInterface $issueDate, ?\App\Entity\User $user = null): array
    {
        $resolvedUser = $user ?? $company->getCreatedBy();
        $invoice = $this->invoiceManager->create($company, $documentData, $resolvedUser);
        $this->invoiceManager->issue($invoice, $resolvedUser);

        // Record that this invoice was generated from a recurring template
        $recurringEvent = new DocumentEvent();
        $recurringEvent->setNewStatus($invoice->getStatus());
        $recurringEvent->setMetadata([
            'action' => 'created_from_recurring',
            'recurringInvoiceId' => (string) $ri->getId(),
            'recurringInvoiceReference' => $ri->getReference(),
            'frequency' => $ri->getFrequency(),
        ]);
        $invoice->addEvent($recurringEvent);

        // Copy penalty config to generated invoice
        if ($ri->isPenaltyEnabled()) {
            $invoice->setPenaltyEnabled(true);
            $invoice->setPenaltyPercentPerDay($ri->getPenaltyPercentPerDay());
            $invoice->setPenaltyGraceDays($ri->getPenaltyGraceDays());
        }

        // Schedule auto-email if enabled
        if ($ri->isAutoEmailEnabled()) {
            $sendDate = \DateTime::createFromInterface($issueDate);
            if ($ri->getAutoEmailDayOffset() > 0) {
                $sendDate->modify(sprintf('+%d days', $ri->getAutoEmailDayOffset()));
            }
            $time = $ri->getAutoEmailTime() ?? '09:00';
            [$hour, $minute] = explode(':', $time);
            $scheduledAt = new \DateTimeImmutable(
                $sendDate->format('Y-m-d') . ' ' . $hour . ':' . $minute . ':00'
            );
            $invoice->setScheduledEmailAt($scheduledAt);
        }

        // Update recurring invoice metadata
        $ri->setLastIssuedAt(new \DateTimeImmutable());
        $ri->setLastInvoiceNumber($invoice->getNumber());

        $this->advanceNextIssuanceDate($ri);

        $this->entityManager->flush();

        return [
            'invoiceId' => (string) $invoice->getId(),
            'invoiceNumber' => $invoice->getNumber(),
            'documentType' => 'invoice',
        ];
    }

    /**
     * @return array{invoiceId: string, invoiceNumber: string, documentType: string}
     */
    private function createProforma(RecurringInvoice $ri, \App\Entity\Company $company, array $documentData, \DateTimeInterface $issueDate, ?\App\Entity\User $user = null): array
    {
        // Remove documentType from data — proforma manager doesn't expect it
        unset($documentData['documentType']);

        $resolvedUser = $user ?? $company->getCreatedBy();
        $proforma = $this->proformaInvoiceManager->create($company, $documentData, $resolvedUser);

        // Auto-send and auto-accept the proforma (no manual approval needed for recurring)
        $this->proformaInvoiceManager->send($proforma);
        $this->proformaInvoiceManager->accept($proforma);

        // Update recurring invoice metadata
        $ri->setLastIssuedAt(new \DateTimeImmutable());
        $ri->setLastInvoiceNumber($proforma->getNumber());

        $this->advanceNextIssuanceDate($ri);

        $this->entityManager->flush();

        return [
            'invoiceId' => (string) $proforma->getId(),
            'invoiceNumber' => $proforma->getNumber(),
            'documentType' => 'proforma',
        ];
    }

    private function replaceTemplateVars(?string $text, int $month, int $year, string $rateText = ''): ?string
    {
        if ($text === null) {
            return null;
        }

        return str_replace(
            ['[[luna]]', '[[an]]', '[[luna_nr]]', '[[curs]]'],
            [self::ROMANIAN_MONTHS[$month], (string) $year, str_pad((string) $month, 2, '0', STR_PAD_LEFT), $rateText],
            $text,
        );
    }

    private function calculateDueDate(RecurringInvoice $ri, \DateTimeInterface $issueDate): ?\DateTimeInterface
    {
        if ($ri->getDueDateType() === 'days' && $ri->getDueDateDays() !== null) {
            $due = \DateTime::createFromInterface($issueDate);
            $due->modify(sprintf('+%d days', $ri->getDueDateDays()));
            return $due;
        }

        if ($ri->getDueDateType() === 'fixed_day' && $ri->getDueDateFixedDay() !== null) {
            $due = \DateTime::createFromInterface($issueDate);
            $fixedDay = min($ri->getDueDateFixedDay(), 28);
            $due->setDate((int) $due->format('Y'), (int) $due->format('n'), $fixedDay);
            // If the fixed day is before or on the issue date, move to next month
            if ($due <= $issueDate) {
                $due->modify('+1 month');
                $due->setDate((int) $due->format('Y'), (int) $due->format('n'), $fixedDay);
            }
            return $due;
        }

        return null;
    }

    private function advanceNextIssuanceDate(RecurringInvoice $ri): void
    {
        $current = $ri->getNextIssuanceDate();
        if (!$current) {
            return;
        }

        $next = \DateTime::createFromInterface($current);

        switch ($ri->getFrequency()) {
            case 'once':
                // One-time invoice: deactivate, no next date
                $ri->setIsActive(false);
                $ri->setNextIssuanceDate(null);
                return;

            case 'weekly':
                $next->modify('+1 week');
                break;

            case 'monthly':
                $next->modify('+1 month');
                $day = min($ri->getFrequencyDay(), 28);
                $next->setDate((int) $next->format('Y'), (int) $next->format('n'), $day);
                break;

            case 'bimonthly':
                $next->modify('+2 months');
                $day = min($ri->getFrequencyDay(), 28);
                $next->setDate((int) $next->format('Y'), (int) $next->format('n'), $day);
                break;

            case 'quarterly':
                $next->modify('+3 months');
                $day = min($ri->getFrequencyDay(), 28);
                $next->setDate((int) $next->format('Y'), (int) $next->format('n'), $day);
                break;

            case 'semi_annually':
                $next->modify('+6 months');
                $day = min($ri->getFrequencyDay(), 28);
                $next->setDate((int) $next->format('Y'), (int) $next->format('n'), $day);
                break;

            case 'yearly':
                $next->modify('+1 year');
                $month = $ri->getFrequencyMonth() ?? (int) $current->format('n');
                $day = min($ri->getFrequencyDay(), 28);
                $next->setDate((int) $next->format('Y'), $month, $day);
                break;
        }

        $ri->setNextIssuanceDate($next);

        // Auto-deactivate if past stop date
        if ($ri->getStopDate() && $next > $ri->getStopDate()) {
            $ri->setIsActive(false);
        }
    }
}
