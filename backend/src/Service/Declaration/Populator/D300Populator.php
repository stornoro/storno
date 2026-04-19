<?php

namespace App\Service\Declaration\Populator;

use App\Entity\Company;
use App\Enum\InvoiceDirection;
use App\Repository\InvoiceRepository;
use App\Service\Declaration\DeclarationDataPopulatorInterface;

/**
 * Populates D300 (Decont TVA) data from invoice records.
 *
 * Aggregates invoices by direction (outgoing = collected VAT, incoming = deductible VAT)
 * and groups by VAT rate into ANAF D300 rows.
 *
 * Collected: R1 (19%), R2 (9%), R3 (5%), R4 (reverse charge), R5 (intra-community)
 * Deductible: R21 (19%), R22 (9%), R23 (5%), R19 (reverse charge deductible), R20 (intra-community deductible)
 */
class D300Populator implements DeclarationDataPopulatorInterface
{
    public function __construct(
        private readonly InvoiceRepository $invoiceRepository,
    ) {}

    public function supportsType(string $type): bool
    {
        return $type === 'd300';
    }

    public function populate(Company $company, int $year, int $month, string $periodType): array
    {
        $dateFrom = new \DateTime(sprintf('%04d-%02d-01', $year, $month));

        if ($periodType === 'quarterly') {
            $quarter = (int) ceil($month / 3);
            $startMonth = ($quarter - 1) * 3 + 1;
            $dateFrom = new \DateTime(sprintf('%04d-%02d-01', $year, $startMonth));
            $dateTo = (clone $dateFrom)->modify('+2 months')->modify('last day of this month');
        } else {
            $dateTo = (clone $dateFrom)->modify('last day of this month');
        }

        $invoices = $this->invoiceRepository->findByCompanyFiltered($company, [
            'dateFrom' => $dateFrom->format('Y-m-d'),
            'dateTo' => $dateTo->format('Y-m-d'),
            'excludeCancelled' => true,
        ], 10000);

        $rows = [];
        $invoiceCountIssued = 0;
        $invoiceCountReceived = 0;

        foreach ($invoices as $invoice) {
            $direction = $invoice->getDirection();
            if ($direction === null) {
                continue;
            }

            $isSale = $direction === InvoiceDirection::OUTGOING;
            if ($isSale) {
                $invoiceCountIssued++;
            } else {
                $invoiceCountReceived++;
            }

            foreach ($invoice->getLines() as $line) {
                $vatCat = $line->getVatCategoryCode();
                $vatRate = (float) $line->getVatRate();
                $base = $line->getLineTotal();
                $vat = $line->getVatAmount();

                $rowKeys = $this->resolveRowKeys($isSale, $vatCat, $vatRate);

                foreach ($rowKeys as [$baseKey, $vatKey]) {
                    $rows[$baseKey] = bcadd($rows[$baseKey] ?? '0', $base, 2);
                    $rows[$vatKey] = bcadd($rows[$vatKey] ?? '0', $vat, 2);
                }
            }
        }

        // Compute totals
        $totalCollected = '0';
        $totalDeductible = '0';

        // Collected VAT rows: R1_2, R2_2, R3_2, R4_2, R5_2, etc.
        foreach (['R1_2', 'R2_2', 'R3_2', 'R4_2', 'R5_2', 'R6_2', 'R7_2', 'R8_2', 'R9_2', 'R10_2', 'R11_2', 'R12_2'] as $key) {
            $totalCollected = bcadd($totalCollected, $rows[$key] ?? '0', 2);
        }

        // Deductible VAT rows: R21_2, R22_2, R23_2, R19_2, R20_2, etc.
        foreach (['R19_2', 'R20_2', 'R21_2', 'R22_2', 'R23_2', 'R24_2', 'R25_2', 'R26_2', 'R27_2', 'R28_2', 'R29_2'] as $key) {
            $totalDeductible = bcadd($totalDeductible, $rows[$key] ?? '0', 2);
        }

        $rows['R13_2'] = $totalCollected;
        $rows['R30_2'] = $totalDeductible;

        $netVat = bcsub($totalCollected, $totalDeductible, 2);

        return [
            'rows' => $rows,
            'totals' => [
                'collected' => $totalCollected,
                'deductible' => $totalDeductible,
                'net' => $netVat,
            ],
            'invoiceCounts' => [
                'issued' => $invoiceCountIssued,
                'received' => $invoiceCountReceived,
            ],
        ];
    }

    /**
     * Map VAT category code + rate to ANAF D300 row keys.
     *
     * @return array<array{string, string}> Array of [baseKey, vatKey] pairs
     */
    private function resolveRowKeys(bool $isSale, string $vatCat, float $vatRate): array
    {
        if ($isSale) {
            // Collected VAT
            return match (true) {
                $vatCat === 'AE' => [['R4_1', 'R4_2']],   // Reverse charge collected
                $vatCat === 'K' => [['R5_1', 'R5_2']],    // Intra-community collected
                $vatCat === 'E' || $vatCat === 'Z' => [['R14_1', 'R14_2']], // Exempt
                abs($vatRate - 19) < 0.5 => [['R1_1', 'R1_2']],
                abs($vatRate - 9) < 0.5 => [['R2_1', 'R2_2']],
                abs($vatRate - 5) < 0.5 => [['R3_1', 'R3_2']],
                default => [['R12_1', 'R12_2']], // Other
            };
        }

        // Deductible VAT
        return match (true) {
            $vatCat === 'AE' => [['R19_1', 'R19_2']],  // Reverse charge deductible
            $vatCat === 'K' => [['R20_1', 'R20_2']],   // Intra-community deductible
            $vatCat === 'E' || $vatCat === 'Z' => [],   // Exempt — no deduction
            abs($vatRate - 19) < 0.5 => [['R21_1', 'R21_2']],
            abs($vatRate - 9) < 0.5 => [['R22_1', 'R22_2']],
            abs($vatRate - 5) < 0.5 => [['R23_1', 'R23_2']],
            default => [['R29_1', 'R29_2']], // Other
        };
    }
}
