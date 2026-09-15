<?php

namespace App\Service\Calendar;

use App\Entity\Company;
use App\Entity\Dosar;
use App\Entity\TaxDeclaration;
use App\Enum\DeclarationStatus;
use App\Enum\DeclarationType;
use App\Repository\DosarRepository;
use App\Repository\InvoiceRepository;
use App\Repository\TaxDeclarationRepository;

/**
 * The fiscal deadlines a company has to meet, derived from its profile (VAT payer and period,
 * income tax period, employees, person or company) and from its invoices (intra-community
 * operations, foreign suppliers). Each deadline is moved to the next working day when it falls
 * on a weekend or a legal holiday, and is marked `filed` when a submitted or accepted declaration
 * of that type exists for the period, `overdue` when it is past due and not filed.
 *
 * Rental-contract dosare add their own deadlines: the C168 registration / amendment /
 * termination (30 days from the contract event, carried by the dosar until ANAF accepts the
 * filing), the estimated Declarația unică a natural person owes within 30 days of a new
 * rental income, and the end of the contract (addendum or C168 termination to prepare).
 *
 * Not covered here because they are handled elsewhere: the 5-day e-Factura submission window
 * of issued invoices and the expiry of ANAF tokens / certificates.
 */
final class FiscalCalendarService
{
    public const STATUS_DUE = 'due';
    public const STATUS_FILED = 'filed';
    public const STATUS_OVERDUE = 'overdue';

    /** Deadlines already past are still listed this many days back, so an unfiled one shows as overdue. */
    public const OVERDUE_LOOKBACK_DAYS = 31;

    /** EU member states other than Romania: a counterparty there makes the period an intra-community one (D390). */
    public const EU_COUNTRIES = [
        'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'EL', 'HU', 'IE', 'IT', 'LV', 'LT',
        'LU', 'MT', 'NL', 'PL', 'PT', 'SK', 'SI', 'ES', 'SE', 'XI',
    ];

    /** Statuses that count as "filed" for the calendar. */
    private const FILED_STATUSES = [DeclarationStatus::SUBMITTED, DeclarationStatus::PROCESSING, DeclarationStatus::ACCEPTED];

    private const LABELS = [
        'D300' => 'Decont de TVA',
        'D390' => 'Declarație recapitulativă (VIES)',
        'D394' => 'Declarație informativă livrări/achiziții',
        'D301' => 'Decont special de TVA',
        'D100' => 'Obligații de plată la bugetul de stat',
        'D112' => 'Contribuții sociale și impozit pe salarii',
        'D406' => 'SAF-T',
        'D212' => 'Declarația unică',
        'BILANT' => 'Situații financiare anuale',
        'C168' => 'Înregistrarea contractului de închiriere',
        'D212_ESTIMAT' => 'Declarația unică estimativă (venit nou din chirii)',
        'CONTRACT_END' => 'Expirarea contractului de închiriere',
    ];

    /** Days a natural person has to declare the estimated income of a new rental contract (D212, cap. II). */
    public const ESTIMATE_DAYS = 30;

    public function __construct(
        private readonly RomanianHolidays $holidays,
        private readonly InvoiceRepository $invoiceRepository,
        private readonly TaxDeclarationRepository $declarationRepository,
        private readonly DosarRepository $dosarRepository,
    ) {
    }

    /**
     * Deadlines due between `from - OVERDUE_LOOKBACK_DAYS` and `from + days`, ordered by due date.
     *
     * @return list<array{code: string, label: string, dueDate: string, nominalDueDate: string, daysLeft: int,
     *   period: array{year: int, month?: int, quarter?: int, from: string, to: string},
     *   appliesBecause: string, declarationType: ?string, status: string, dosarId?: string, dosarTitle?: string}>
     */
    public function upcoming(Company $company, \DateTimeImmutable $from, int $days = 60): array
    {
        $from = $from->setTime(0, 0);
        $windowStart = $from->modify(sprintf('-%d days', self::OVERDUE_LOOKBACK_DAYS));
        $windowEnd = $from->modify(sprintf('+%d days', max(0, $days)));

        $filed = $this->filedIndex($company);
        $candidates = [];

        // Deadlines fall in the month after the period (or in May for the annual ones): walk the
        // months from the lookback start to the end of the window and derive the period from each.
        $cursor = $windowStart->modify('first day of this month');
        $last = $windowEnd->modify('first day of this month');
        while ($cursor <= $last) {
            foreach ($this->deadlinesDueIn($company, (int) $cursor->format('Y'), (int) $cursor->format('n')) as $item) {
                $candidates[] = $item;
            }
            $cursor = $cursor->modify('+1 month');
        }
        foreach ($this->dosarDeadlines($company, $filed) as $item) {
            $candidates[] = $item;
        }

        $items = [];
        foreach ($candidates as $item) {
            $due = new \DateTimeImmutable($item['dueDate']);
            if ($due < $windowStart || $due > $windowEnd) {
                continue;
            }
            $item['daysLeft'] = (int) $from->diff($due)->format('%r%a');
            if (isset($item['dosarId'])) {
                // a dosar deadline is filed only through that dosar, never by another declaration of the same type
                $item['status'] = ($item['filed'] ?? false) ? self::STATUS_FILED : ($due < $from ? self::STATUS_OVERDUE : self::STATUS_DUE);
                unset($item['filed']);
            } else {
                $item['status'] = $this->status($item, $filed, $from);
            }
            // Deadlines Storno cannot see filed (financial statements, a contract's end) are never reported overdue
            if ($item['declarationType'] === null && $item['status'] === self::STATUS_OVERDUE) {
                continue;
            }
            $items[] = $item;
        }

        usort($items, static fn (array $a, array $b) => [$a['dueDate'], $a['code']] <=> [$b['dueDate'], $b['code']]);

        return $items;
    }

    /** @return list<array<string, mixed>> the deadlines whose nominal due date is in the given month */
    private function deadlinesDueIn(Company $company, int $year, int $month): array
    {
        $items = [];
        $individual = $company->isIndividual();
        $vatPayer = !$individual && $company->isVatPayer();
        [$prevYear, $prevMonth] = $month === 1 ? [$year - 1, 12] : [$year, $month - 1];
        $quarterEnded = $prevMonth % 3 === 0; // the month that just ended closes a quarter
        $prevQuarter = intdiv($prevMonth - 1, 3) + 1;

        $monthly = ['year' => $prevYear, 'month' => $prevMonth] + $this->monthBounds($prevYear, $prevMonth);
        $quarterly = $quarterEnded ? ['year' => $prevYear, 'quarter' => $prevQuarter] + $this->quarterBounds($prevYear, $prevQuarter) : null;

        $vatPeriod = $company->getVatPeriod() === Company::PERIOD_QUARTERLY ? $quarterly : $monthly;
        $incomeTaxPeriod = $company->getIncomeTaxPeriod() === Company::PERIOD_QUARTERLY ? $quarterly : $monthly;
        // SAF-T follows the VAT period; a company without VAT registration reports quarterly.
        $saftPeriod = $vatPayer ? $vatPeriod : $quarterly;

        if ($vatPayer && $vatPeriod !== null) {
            $items[] = $this->item('D300', $year, $month, 25, $vatPeriod, 'vat_payer', DeclarationType::D300);
            $items[] = $this->item('D394', $year, $month, 30, $vatPeriod, 'vat_payer', DeclarationType::D394);
        }
        if ($vatPayer && $this->invoiceRepository->hasIntraCommunityOperations($company, new \DateTimeImmutable($monthly['from']), new \DateTimeImmutable($monthly['to'] . ' 23:59:59'), self::EU_COUNTRIES)) {
            $items[] = $this->item('D390', $year, $month, 25, $monthly, 'intra_community_operations', DeclarationType::D390);
        }
        if (!$individual && !$vatPayer && $this->invoiceRepository->hasForeignSupplierInvoices($company, new \DateTimeImmutable($monthly['from']), new \DateTimeImmutable($monthly['to'] . ' 23:59:59'))) {
            $items[] = $this->item('D301', $year, $month, 25, $monthly, 'non_vat_payer_foreign_suppliers', DeclarationType::D301);
        }
        if (!$individual && $incomeTaxPeriod !== null) {
            $items[] = $this->item('D100', $year, $month, 25, $incomeTaxPeriod, 'income_tax', DeclarationType::D100);
        }
        if ($company->hasEmployees()) {
            $items[] = $this->item('D112', $year, $month, 25, $monthly, 'employees', DeclarationType::D112);
        }
        if (!$individual && $saftPeriod !== null) {
            $lastDay = (int) (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('t');
            $items[] = $this->item('D406', $year, $month, $lastDay, $saftPeriod, 'saft', DeclarationType::D406);
        }
        if ($month === 5) {
            $annual = ['year' => $year - 1, 'from' => sprintf('%04d-01-01', $year - 1), 'to' => sprintf('%04d-12-31', $year - 1)];
            if ($individual) {
                $items[] = $this->item('D212', $year, 5, 25, $annual, 'individual', DeclarationType::D212);
            } else {
                // Annual financial statements: the last working day of May.
                $nominal = new \DateTimeImmutable(sprintf('%04d-05-31', $year));
                $items[] = [
                    'code' => 'BILANT',
                    'label' => self::LABELS['BILANT'],
                    'nominalDueDate' => $nominal->format('Y-m-d'),
                    'dueDate' => $this->holidays->previousWorkingDay($nominal)->format('Y-m-d'),
                    'period' => $annual,
                    'appliesBecause' => 'company',
                    'declarationType' => null,
                ];
            }
        }

        return $items;
    }

    /** @param array<string, mixed> $period */
    private function item(string $code, int $year, int $month, int $day, array $period, string $because, ?DeclarationType $type): array
    {
        $daysInMonth = (int) (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('t');
        $nominal = new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, min($day, $daysInMonth)));

        return [
            'code' => $code,
            'label' => self::LABELS[$code],
            'nominalDueDate' => $nominal->format('Y-m-d'),
            'dueDate' => $this->holidays->nextWorkingDay($nominal)->format('Y-m-d'),
            'period' => $period,
            'appliesBecause' => $because,
            'declarationType' => $type?->value,
        ];
    }

    /**
     * Deadlines carried by the active rental-contract dosare of the company.
     *
     * @param array{byType: array<string, list<array{0: int, 1: int, 2: string, 3: ?string}>>, byDosar: array<string, list<string>>} $filed
     * @return list<array<string, mixed>>
     */
    private function dosarDeadlines(Company $company, array $filed): array
    {
        $items = [];
        foreach ($this->dosarRepository->findForCompany($company, Dosar::TYPE_RENTAL_CONTRACT) as $dosar) {
            if (!$dosar instanceof Dosar || $dosar->getStatus() === Dosar::STATUS_CLOSED) {
                continue;
            }
            $subject = $dosar->getSubject();
            $start = $this->subjectDate($subject['deLa'] ?? $subject['data'] ?? null);
            $end = $this->subjectDate($subject['panaLa'] ?? null);
            $dosarId = (string) $dosar->getId();
            $period = [
                'year' => (int) ($start?->format('Y') ?? date('Y')),
                'from' => $start?->format('Y-m-d') ?? '',
                'to' => $end?->format('Y-m-d') ?? '',
            ];
            $base = ['period' => $period, 'appliesBecause' => 'rental_contract', 'dosarId' => $dosarId, 'dosarTitle' => $dosar->getTitle()];

            // C168: the dosar carries the 30-day deadline until ANAF accepts the filing (then it is cleared).
            $deadline = $dosar->getDeadlineAt();
            if ($deadline !== null && ($dosar->getDeadlineLabel() === null || str_contains($dosar->getDeadlineLabel(), 'C168'))) {
                $nominal = $deadline->setTime(0, 0);
                $items[] = $base + [
                    'code' => 'C168',
                    'label' => $dosar->getDeadlineLabel() ?? self::LABELS['C168'],
                    'nominalDueDate' => $nominal->format('Y-m-d'),
                    'dueDate' => $this->holidays->nextWorkingDay($nominal)->format('Y-m-d'),
                    'declarationType' => DeclarationType::C168->value,
                    'filed' => in_array(DeclarationType::C168->value, $filed['byDosar'][$dosarId] ?? [], true),
                ];
            }

            // A natural person who starts earning rent declares the estimated income within 30 days.
            if ($company->isIndividual() && $start !== null) {
                $nominal = $start->modify(sprintf('+%d days', self::ESTIMATE_DAYS));
                $estimateFiled = false;
                foreach ($filed['byType'][DeclarationType::D212->value] ?? [] as [$year, , , $submittedAt]) {
                    if ($year === (int) $start->format('Y') && ($submittedAt === null || $submittedAt >= $start->format('Y-m-d'))) {
                        $estimateFiled = true;
                    }
                }
                $items[] = $base + [
                    'code' => 'D212_ESTIMAT',
                    'label' => self::LABELS['D212_ESTIMAT'],
                    'nominalDueDate' => $nominal->format('Y-m-d'),
                    'dueDate' => $this->holidays->nextWorkingDay($nominal)->format('Y-m-d'),
                    'declarationType' => DeclarationType::D212->value,
                    'filed' => $estimateFiled,
                ];
            }

            // The contract's end: prepare the addendum or the C168 termination.
            if ($end !== null) {
                $items[] = $base + [
                    'code' => 'CONTRACT_END',
                    'label' => self::LABELS['CONTRACT_END'],
                    'nominalDueDate' => $end->format('Y-m-d'),
                    'dueDate' => $end->format('Y-m-d'),
                    'declarationType' => null,
                    'filed' => false,
                ];
            }
        }

        // One estimated D212 covers every contract that starts on the same day: keep a single item per due date.
        $seen = [];
        $items = array_values(array_filter($items, static function (array $item) use (&$seen): bool {
            if ($item['code'] !== 'D212_ESTIMAT') {
                return true;
            }
            if (isset($seen[$item['dueDate']])) {
                return false;
            }
            $seen[$item['dueDate']] = true;

            return true;
        }));

        return $items;
    }

    /** Dates in a dosar subject are DD.MM.YYYY or YYYY-MM-DD. */
    private function subjectDate(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $value = trim($value);
        $date = preg_match('/^\d{1,2}\.\d{1,2}\.\d{4}$/', $value) === 1
            ? \DateTimeImmutable::createFromFormat('!d.m.Y', $value)
            : \DateTimeImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10));

        return $date instanceof \DateTimeImmutable ? $date : null;
    }

    /** @return array{from: string, to: string} */
    private function monthBounds(int $year, int $month): array
    {
        $first = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));

        return ['from' => $first->format('Y-m-d'), 'to' => $first->modify('last day of this month')->format('Y-m-d')];
    }

    /** @return array{from: string, to: string} */
    private function quarterBounds(int $year, int $quarter): array
    {
        $first = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, ($quarter - 1) * 3 + 1));

        return ['from' => $first->format('Y-m-d'), 'to' => $first->modify('+2 months')->modify('last day of this month')->format('Y-m-d')];
    }

    /**
     * Filed declarations of the company: by type as [year, month, periodType, submittedAt] and,
     * for the ones filed from a dosar, the types per dosar id.
     *
     * @return array{byType: array<string, list<array{0: int, 1: int, 2: string, 3: ?string}>>, byDosar: array<string, list<string>>}
     */
    private function filedIndex(Company $company): array
    {
        $index = ['byType' => [], 'byDosar' => []];
        foreach ($this->declarationRepository->findByCompanyAndStatuses($company, self::FILED_STATUSES) as $declaration) {
            if ($declaration instanceof TaxDeclaration) {
                $filedAt = $declaration->getSubmittedAt() ?? $declaration->getCreatedAt();
                $index['byType'][$declaration->getType()->value][] = [$declaration->getYear(), $declaration->getMonth(), $declaration->getPeriodType(), $filedAt?->format('Y-m-d')];
                if ($declaration->getDosar() !== null) {
                    $index['byDosar'][(string) $declaration->getDosar()->getId()][] = $declaration->getType()->value;
                }
            }
        }

        return $index;
    }

    /** @param array<string, mixed> $item @param array{byType: array<string, list<array{0: int, 1: int, 2: string, 3: ?string}>>, byDosar: array<string, list<string>>} $filed */
    private function status(array $item, array $filed, \DateTimeImmutable $today): string
    {
        $type = $item['declarationType'];
        if ($type !== null) {
            $period = $item['period'];
            foreach ($filed['byType'][$type] ?? [] as [$year, $month]) {
                if ($year !== $period['year']) {
                    continue;
                }
                if (isset($period['quarter']) && intdiv($month - 1, 3) + 1 === $period['quarter']) {
                    return self::STATUS_FILED;
                }
                if (isset($period['month']) && $month === $period['month']) {
                    return self::STATUS_FILED;
                }
                if (!isset($period['month']) && !isset($period['quarter'])) { // annual: the year is enough
                    return self::STATUS_FILED;
                }
            }
        }

        return new \DateTimeImmutable($item['dueDate']) < $today ? self::STATUS_OVERDUE : self::STATUS_DUE;
    }
}
