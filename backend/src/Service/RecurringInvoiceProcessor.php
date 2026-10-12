<?php

namespace App\Service;

use App\Entity\DocumentEvent;
use App\Entity\RecurringInvoice;
use App\Service\Recurring\TemplateExpression;
use App\Enum\DocumentStatus;
use App\Enum\DocumentType;
use App\Manager\InvoiceManager;
use App\Manager\ProformaInvoiceManager;
use App\Repository\RecurringInvoiceRepository;
use App\Service\Centrifugo\CentrifugoService;
use App\Service\ExchangeRateService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;

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
        private readonly CentrifugoService $centrifugo,
        private readonly LockFactory $lockFactory,
        private readonly LicenseManager $licenseManager,
    ) {}

    /**
     * Process all recurring invoices due on or before the given date.
     *
     * @return array{processed: int, errors: int, invoices: string[]}
     */
    public function processRecurringInvoices(\DateTimeInterface $date, int $limit = 100, bool $dryRun = false): array
    {
        // Global lock prevents concurrent cron runs from double-processing
        $lock = $this->lockFactory->createLock('recurring-invoices-processing', 600);
        if (!$lock->acquire()) {
            $this->logger->warning('Recurring invoice processing already in progress, skipping.');
            return ['processed' => 0, 'errors' => 0, 'invoices' => []];
        }

        try {
            return $this->doProcess($date, $limit, $dryRun);
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{processed: int, errors: int, invoices: string[]}
     */
    private function doProcess(\DateTimeInterface $date, int $limit, bool $dryRun): array
    {
        $dueItems = $this->recurringInvoiceRepository->findDueForProcessing($date, $limit);

        $processed = 0;
        $errors = 0;
        $invoiceNumbers = [];

        foreach ($dueItems as $recurringInvoice) {
            // Plan gate: templates of organizations without the feature stay due but are not issued
            $org = $recurringInvoice->getCompany()?->getOrganization();
            if ($org !== null && !$this->licenseManager->canUseRecurringInvoices($org)) {
                $this->logger->info('Skipping recurring invoice {id}: recurring invoices are not available on the {plan} plan', [
                    'id' => (string) $recurringInvoice->getId(),
                    'organizationId' => (string) $org->getId(),
                    'plan' => $this->licenseManager->getEffectivePlan($org),
                ]);
                continue;
            }

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
        // Per-item lock prevents double-click or concurrent API calls
        $lock = $this->lockFactory->createLock('recurring-invoice-issue-' . $ri->getId(), 60);
        if (!$lock->acquire()) {
            throw new \RuntimeException('This recurring invoice is already being processed.');
        }

        try {
            $result = $this->processOne($ri, new \DateTimeImmutable(), false, $user);
            if (!$result) {
                throw new \RuntimeException('Failed to generate document from recurring template.');
            }

            return $result;
        } finally {
            $lock->release();
        }
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

        // BNR rates of the issue date, fetched once per currency; each one also gets an audit note
        $rates = [];
        $rateNotes = [];
        $rateFor = function (string $currency) use (&$rates, &$rateNotes, $issueDate, $ri): ?float {
            $currency = strtoupper($currency);
            if (!array_key_exists($currency, $rates)) {
                $rates[$currency] = $this->exchangeRateService->getRate($currency);
                if ($rates[$currency] !== null) {
                    $rateNotes[$currency] = sprintf('Curs BNR %s: 1 %s = %s RON', $issueDate->format('d.m.Y'), $currency, number_format($rates[$currency], 4, '.', ''));
                } else {
                    $this->logger->warning('Cannot resolve exchange rate for {currency}, using template prices as-is', [
                        'currency' => $currency,
                        'recurringInvoiceId' => (string) $ri->getId(),
                    ]);
                }
            }

            return $rates[$currency];
        };

        // Values the [[suma:…]] / [[sold]] placeholders can use, and the balance this issuance leaves
        [$vars, $balanceAfter] = $this->expressionVars($ri, $rateFor, $month, $year);

        // Lines with template variable replacement and per-line currency conversion
        $lines = [];
        foreach ($ri->getLines() as $line) {
            $unitPrice = $line->getUnitPrice();
            $discount = $line->getDiscount();

            // Price rule: refresh product price if needed
            $priceRule = $line->getPriceRule();
            if ($priceRule === 'updated_product' && $line->getProduct()) {
                $this->entityManager->refresh($line->getProduct());
                $unitPrice = $line->getProduct()->getDefaultPrice();
            }

            // Price rule: a unit price computed from the rates and the running balance, e.g. 2000*curs - rata
            $priced = false;
            if ($priceRule === 'expression' && $line->getPriceExpression() !== null) {
                try {
                    $unitPrice = number_format(max(0.0, TemplateExpression::evaluate($line->getPriceExpression(), $vars)), 2, '.', '');
                    $priced = true;
                } catch (\InvalidArgumentException $e) {
                    $this->logger->warning('Recurring template {id}: price expression cannot be evaluated ({error}); the template price is used', [
                        'id' => (string) $ri->getId(),
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Per-line reference currency conversion (applies for bnr_rate / bnr_rate_markup price rules,
            // or legacy behaviour when referenceCurrency is set)
            $lineRefCurrency = $line->getReferenceCurrency();
            if ($lineRefCurrency && $ri->getCurrency() === 'RON') {
                $rate = $rateFor($lineRefCurrency);
                if ($rate !== null) {
                    $markup = $line->getMarkupPercent() ? (float) $line->getMarkupPercent() : 0;
                    $multiplier = $rate * (1 + $markup / 100);
                    if (!$priced) { // an expression already yields the document currency
                        $unitPrice = number_format((float) $unitPrice * $multiplier, 2, '.', '');
                    }
                    $discount = number_format((float) $discount * $multiplier, 2, '.', '');
                }
            }

            $lines[] = [
                'description' => $this->replaceTemplateVars($line->getDescription(), $month, $year, '', $vars),
                'quantity' => $line->getQuantity(),
                'unitOfMeasure' => $line->getUnitOfMeasure(),
                'unitPrice' => $unitPrice,
                'vatRate' => $line->getVatRate(),
                'vatCategoryCode' => $line->getVatCategoryCode(),
                'discount' => $discount,
                'discountPercent' => $line->getDiscountPercent(),
                'productCode' => $line->getProductCode(),
                'productId' => $line->getProduct() ? (string) $line->getProduct()->getId() : null,
            ];
        }
        $documentData['lines'] = $lines;

        // Auto-apply VAT rules (reverse charge, OSS, export) based on client country
        $documentData['autoApplyVatRules'] = true;

        // Build exchange rate text for [[curs]] template variable
        $rateText = !empty($rateNotes) ? implode("\n", $rateNotes) : '';

        // Now replace template variables in notes (including [[curs]], [[suma:…]] and [[sold]])
        $documentData['notes'] = $this->replaceTemplateVars($documentData['notes'], $month, $year, $rateText, $vars);

        if ($dryRun) {
            $this->advanceNextIssuanceDate($ri);
            return [
                'invoiceId' => '',
                'invoiceNumber' => '[DRY-RUN] ' . ($ri->getReference() ?? (string) $ri->getId()),
                'documentType' => $ri->getDocumentType()->value,
            ];
        }

        // Create the document based on documentType
        $result = $ri->getDocumentType() === DocumentType::PROFORMA
            ? $this->createProforma($ri, $company, $documentData, $issueDate, $user)
            : $this->createInvoice($ri, $company, $documentData, $issueDate, $user);

        $this->settleBalance($ri, $balanceAfter);

        return $result;
    }

    /**
     * The values available to [[suma:…]] and the balance placeholders for this issuance:
     * `curs` (BNR rate of the first reference currency, EUR when the lines have none), `curs_XXX`
     * for any currency named in an expression, `luna_nr`, `an`, and when the template keeps a
     * running balance: `sold_anterior` (before this invoice), `rata` (what this invoice deducts,
     * never more than what is left) and `sold` (what remains afterwards).
     *
     * @param callable(string): ?float $rateFor
     * @return array{0: array<string, float>, 1: ?string} the vars and the balance to store after issuing
     */
    private function expressionVars(RecurringInvoice $ri, callable $rateFor, int $month, int $year): array
    {
        $vars = ['luna_nr' => (float) $month, 'an' => (float) $year];

        $referenced = [];
        foreach ([$ri->getNotes(), $ri->getBalanceStep()] as $text) {
            if ($text !== null && preg_match_all('/\[\[suma:([^\]]+)\]\]/u', $text, $m)) {
                foreach ($m[1] as $expr) {
                    $referenced = array_merge($referenced, TemplateExpression::names($expr));
                }
            }
        }
        foreach ($ri->getLines() as $line) {
            if ($line->getDescription() !== null && preg_match_all('/\[\[suma:([^\]]+)\]\]/u', $line->getDescription(), $m)) {
                foreach ($m[1] as $expr) {
                    $referenced = array_merge($referenced, TemplateExpression::names($expr));
                }
            }
            if ($line->getPriceRule() === 'expression' && $line->getPriceExpression() !== null) {
                $referenced = array_merge($referenced, TemplateExpression::names($line->getPriceExpression()));
            }
        }
        if ($ri->getBalanceStep() !== null) {
            $referenced = array_merge($referenced, TemplateExpression::names($ri->getBalanceStep()));
        }

        $firstCurrency = null;
        foreach ($ri->getLines() as $line) {
            if ($line->getReferenceCurrency()) {
                $firstCurrency = strtoupper($line->getReferenceCurrency());
                break;
            }
        }
        foreach (array_unique($referenced) as $name) {
            if ($name === 'curs') {
                $vars['curs'] = (float) ($rateFor($firstCurrency ?? 'EUR') ?? 0.0);
            } elseif (preg_match('/^curs_([A-Za-z]{3})$/', $name, $cm)) {
                $vars[$name] = (float) ($rateFor($cm[1]) ?? 0.0);
            }
        }

        $balanceAfter = null;
        if ($ri->getBalance() !== null) {
            $before = (float) $ri->getBalance();
            $step = 0.0;
            if ($ri->getBalanceStep() !== null) {
                try {
                    $step = max(0.0, TemplateExpression::evaluate($ri->getBalanceStep(), $vars + ['sold_anterior' => $before]));
                } catch (\InvalidArgumentException $e) {
                    $this->logger->warning('Recurring template {id}: balance step cannot be evaluated ({error}); the balance is left unchanged', [
                        'id' => (string) $ri->getId(),
                        'error' => $e->getMessage(),
                    ]);
                }
            }
            $rata = round(min($step, $before), 2);
            $after = round($before - $rata, 2);
            $vars += ['sold_anterior' => $before, 'rata' => $rata, 'sold' => $after];
            $balanceAfter = number_format($after, 2, '.', '');
        }

        return [$vars, $balanceAfter];
    }

    /** Stores the balance left after an issuance; a template whose balance reached zero is paused. */
    private function settleBalance(RecurringInvoice $ri, ?string $balanceAfter): void
    {
        if ($balanceAfter === null || $ri->getBalance() === null) {
            return;
        }
        $ri->setBalance($balanceAfter);
        if ((float) $balanceAfter <= 0.0 && $ri->isActive()) {
            $ri->setIsActive(false);
            $this->logger->info('Recurring template {id}: the balance reached zero, the template was paused', ['id' => (string) $ri->getId()]);
        }
        $this->entityManager->flush();
    }

    /** The values a balance step is validated against (any rate-like name resolves to 1). */
    public static function sampleExpressionVars(): array
    {
        return ['curs' => 1.0, 'sold' => 0.0, 'sold_anterior' => 0.0, 'rata' => 0.0, 'luna_nr' => 1.0, 'an' => 2026.0];
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
        $ri->setLastInvoiceId($invoice->getId());
        $ri->setLastDocumentType('invoice');

        $this->advanceNextIssuanceDate($ri);

        $this->entityManager->flush();

        $this->publishInvoiceChange($invoice->getCompany(), 'invoice.created');

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
        $ri->setLastInvoiceId($proforma->getId());
        $ri->setLastDocumentType('proforma');

        $this->advanceNextIssuanceDate($ri);

        $this->entityManager->flush();

        $this->publishInvoiceChange($company, 'invoice.created');

        return [
            'invoiceId' => (string) $proforma->getId(),
            'invoiceNumber' => $proforma->getNumber(),
            'documentType' => 'proforma',
        ];
    }

    private function publishInvoiceChange(\App\Entity\Company $company, string $type): void
    {
        $this->centrifugo->publish('invoices:company_' . $company->getId()->toRfc4122(), [
            'type' => $type,
        ]);
    }

    /** @param array<string, float> $vars */
    private function replaceTemplateVars(?string $text, int $month, int $year, string $rateText = '', array $vars = []): ?string
    {
        if ($text === null) {
            return null;
        }

        // [[suma:expr]] → an amount computed from the BNR rate and the running balance, e.g. [[suma:1900*curs]]
        $text = preg_replace_callback('/\[\[suma:([^\]]+)\]\]/u', function (array $m) use ($vars): string {
            try {
                return self::formatAmount(TemplateExpression::evaluate($m[1], $vars));
            } catch (\InvalidArgumentException $e) {
                $this->logger->warning('Recurring template placeholder {placeholder} cannot be evaluated: {error}', ['placeholder' => $m[0], 'error' => $e->getMessage()]);

                return $m[0];
            }
        }, $text);
        // [[sold]], [[sold_anterior]], [[rata]] → the running balance figures of this issuance
        $text = preg_replace_callback('/\[\[(sold|sold_anterior|rata)\]\]/', function (array $m) use ($vars): string {
            return isset($vars[$m[1]]) ? self::formatAmount($vars[$m[1]]) : $m[0];
        }, $text);

        return preg_replace_callback(
            '/\[\[(luna|luna_nr|an)([+-]\d+)?\]\]|\[\[curs\]\]/',
            function (array $m) use ($month, $year, $rateText): string {
                // [[curs]]
                if ($m[0] === '[[curs]]') {
                    return $rateText;
                }

                $type = $m[1];
                $offset = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 0;

                if ($type === 'an') {
                    return (string) ($year + $offset);
                }

                // For luna / luna_nr, use DateTime to handle year wraparound
                $base = new \DateTime(sprintf('%04d-%02d-01', $year, $month));
                if ($offset !== 0) {
                    $base->modify(sprintf('%+d months', $offset));
                }
                $adjMonth = (int) $base->format('n');

                if ($type === 'luna') {
                    return self::ROMANIAN_MONTHS[$adjMonth];
                }

                // luna_nr
                return str_pad((string) $adjMonth, 2, '0', STR_PAD_LEFT);
            },
            $text,
        );
    }

    /** Romanian number format: 9.633,00 */
    public static function formatAmount(float $value): string
    {
        return number_format($value, 2, ',', '.');
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
