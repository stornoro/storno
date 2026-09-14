<?php

declare(strict_types=1);

namespace App\Service\Declaration\D300;

/**
 * Where a VAT rate lands in the D300 form, per form version.
 *
 * ANAF keeps the XML attribute names stable across form versions (R9 was "19%" until July
 * 2025 and is "21%" since August 2025; rows added later got numbers from R64 upwards), so the
 * printed row number, the label and the attribute are three different things. The mapping
 * printed row → attribute was read from ANAF's own PDF renderers (D300Pdf.jar, Pdf_v7 for
 * 08–12/2025 and Pdf_v8 for 2026) and the rate tolerances from D300Validator.jar.
 */
final class D300Layout
{
    public const LEGACY = 'legacy';   // periods before 2025-08: 19 / 9 / 5 %
    public const V2025H2 = 'v2025h2'; // 08–12/2025: 21 / 11 plus dedicated rows for the old 19 / 9 / 5 %
    public const V2026 = 'v2026';     // from 01/2026: 21 / 11, old rates only as regularisations

    /** Rates abrogated on 2025-08-01 (Law 141/2025). */
    public const ABROGATED_RATES = ['19', '9', '5'];

    public static function forPeriodStart(\DateTimeInterface $periodStart): string
    {
        $ymd = $periodStart->format('Y-m-d');
        if ($ymd >= '2026-01-01') {
            return self::V2026;
        }
        if ($ymd >= '2025-08-01') {
            return self::V2025H2;
        }
        return self::LEGACY;
    }

    /**
     * Attribute prefix of the taxable-sales row for a rate (rd. 9 / 10 / 11 and the 2025-H2 extras),
     * or null when the rate has no row in this layout (→ regularisations, rd. 16).
     */
    public static function salesRow(string $layout, string $rate): ?string
    {
        return match ($layout) {
            self::V2026 => match ($rate) { '21' => 'R9', '11' => 'R10', default => null },
            self::V2025H2 => match ($rate) { '21' => 'R9', '19' => 'R69', '11' => 'R10', '9' => 'R70', '5' => 'R71', default => null },
            default => match ($rate) { '19' => 'R9', '9' => 'R10', '5' => 'R11', default => null },
        };
    }

    /** Taxable domestic purchases (rd. 24 / 25 and the 2025-H2 extras), or null → regularisations (rd. 33). */
    public static function purchasesRow(string $layout, string $rate): ?string
    {
        return match ($layout) {
            self::V2026 => match ($rate) { '21' => 'R22', '11' => 'R23', default => null },
            self::V2025H2 => match ($rate) { '21' => 'R22', '19' => 'R74', '11' => 'R23', '9' => 'R75', '5' => 'R24', default => null },
            default => match ($rate) { '19' => 'R22', '9' => 'R23', '5' => 'R24', default => null },
        };
    }

    /**
     * Sub-rows of rd. 12 (reverse-charge purchases, collected side) for a rate; the deductible
     * mirror (rd. 26 in 2026, rd. 27 in 2025-H2) is the second element.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function reverseChargeRows(string $layout, string $rate): ?array
    {
        return match ($layout) {
            self::V2026 => match ($rate) { '21' => ['R12_1', 'R25_1'], '11' => ['R12_2', 'R25_2'], default => null },
            self::V2025H2 => match ($rate) {
                '21' => ['R12_1', 'R25_1'], '11' => ['R12_2', 'R25_2'], '19' => ['R12_3', 'R25_3'], '9' => ['R72', 'R76'], '5' => ['R73', 'R77'],
                default => null,
            },
            default => match ($rate) { '19' => ['R12_1', 'R25_1'], '9' => ['R12_2', 'R25_2'], '5' => ['R12_3', 'R25_3'], default => null },
        };
    }

    /** Standard rate used to self-assess VAT (reverse charge, intra-community) when the line carries none. */
    public static function standardRate(string $layout): string
    {
        return $layout === self::LEGACY ? '19' : '21';
    }

    /** Rows summed into rd. 19 "TOTAL TAXA COLECTATA" (main rows only; sub-rows like 3.1, 7.1, 12.x are excluded). */
    public static function collectedRows(string $layout): array
    {
        $rows = ['R1', 'R2', 'R3', 'R4', 'R5', 'R6', 'R7', 'R8', 'R9', 'R10', 'R11', 'R12', 'R13', 'R14', 'R15', 'R16', 'R64', 'R65'];
        if ($layout === self::V2025H2) {
            array_splice($rows, 9, 0, ['R69', 'R70', 'R71']);
        }
        return $rows;
    }

    /**
     * Rows summed into rd. 30 (2026) / rd. 31 (2025-H2) "TOTAL TAXA DEDUCTIBILA": sub-rows 20.1,
     * 22.1, 26.x are excluded, and so is the base of rd. 29 (exempt purchases, R26_1) — the
     * validator's own rule for R27_1 stops at rd. 26 / 27.x; R43 / R44 only have a VAT column.
     */
    public static function deductibleRows(string $layout): array
    {
        $rows = ['R18', 'R19', 'R20', 'R21', 'R22', 'R23', 'R24', 'R25', 'R43', 'R44'];
        if ($layout === self::V2025H2) {
            array_splice($rows, 5, 0, ['R74', 'R75']);
        }
        return $rows;
    }

    /** Rows that only have a base column (col. 1). */
    public const BASE_ONLY = ['R1', 'R2', 'R3', 'R3_1', 'R4', 'R13', 'R14', 'R15', 'R26', 'R26_1'];

    /** Rows that only have a VAT column (col. 2). */
    public const VAT_ONLY = ['R43', 'R44'];
}
