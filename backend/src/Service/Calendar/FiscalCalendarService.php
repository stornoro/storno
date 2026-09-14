<?php

namespace App\Service\Calendar;

use App\Entity\Company;
use App\Entity\TaxDeclaration;
use App\Enum\DeclarationStatus;
use App\Enum\DeclarationType;
use App\Repository\InvoiceRepository;
use App\Repository\TaxDeclarationRepository;

/**
 * The fiscal deadlines a company has to meet, derived from its profile (VAT payer and period,
 * income tax period, employees, person or company) and from its invoices (intra-community
 * operations, foreign suppliers). Each deadline is moved to the next working day when it falls
 * on a weekend or a legal holiday, and is marked `filed` when a submitted or accepted declaration
 * of that type exists for the period, `overdue` when it is past due and not filed.
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
    ];

    public function __construct(
        private readonly RomanianHolidays $holidays,
        private readonly InvoiceRepository $invoiceRepository,
        private readonly TaxDeclarationRepository $declarationRepository,
    ) {
    }

    /**
     * Deadlines due between `from - OVERDUE_LOOKBACK_DAYS` and `from + days`, ordered by due date.
     *
     * @return list<array{code: string, label: string, dueDate: string, nominalDueDate: string, daysLeft: int,
     *   period: array{year: int, month?: int, quarter?: int, from: string, to: string},
     *   appliesBecause: string, declarationType: ?string, status: string}>
     */
    public function upcoming(Company $company, \DateTimeImmutable $from, int $days = 60): array
    {
        $from = $from->setTime(0, 0);
        $windowStart = $from->modify(sprintf('-%d days', self::OVERDUE_LOOKBACK_DAYS));
        $windowEnd = $from->modify(sprintf('+%d days', max(0, $days)));

        $filed = $this->filedIndex($company);
        $items = [];

        // Deadlines fall in the month after the period (or in May for the annual ones): walk the
        // months from the lookback start to the end of the window and derive the period from each.
        $cursor = $windowStart->modify('first day of this month');
        $last = $windowEnd->modify('first day of this month');
        while ($cursor <= $last) {
            foreach ($this->deadlinesDueIn($company, (int) $cursor->format('Y'), (int) $cursor->format('n')) as $item) {
                $due = new \DateTimeImmutable($item['dueDate']);
                if ($due < $windowStart || $due > $windowEnd) {
                    continue;
                }
                $item['daysLeft'] = (int) $from->diff($due)->format('%r%a');
                $item['status'] = $this->status($item, $filed, $from);
                // Deadlines Storno cannot see filed (SAF-T, financial statements) are never reported overdue
                if ($item['declarationType'] === null && $item['status'] === self::STATUS_OVERDUE) {
                    continue;
                }
                $items[] = $item;
            }
            $cursor = $cursor->modify('+1 month');
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
            $items[] = $this->item('D406', $year, $month, $lastDay, $saftPeriod, 'saft', null);
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
     * Filed declarations of the company indexed by type: [year, month, periodType] triples.
     *
     * @return array<string, list<array{0: int, 1: int, 2: string}>>
     */
    private function filedIndex(Company $company): array
    {
        $index = [];
        foreach ($this->declarationRepository->findByCompanyAndStatuses($company, self::FILED_STATUSES) as $declaration) {
            if ($declaration instanceof TaxDeclaration) {
                $index[$declaration->getType()->value][] = [$declaration->getYear(), $declaration->getMonth(), $declaration->getPeriodType()];
            }
        }

        return $index;
    }

    /** @param array<string, mixed> $item @param array<string, list<array{0: int, 1: int, 2: string}>> $filed */
    private function status(array $item, array $filed, \DateTimeImmutable $today): string
    {
        $type = $item['declarationType'];
        if ($type !== null) {
            $period = $item['period'];
            foreach ($filed[$type] ?? [] as [$year, $month]) {
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
