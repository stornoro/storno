<?php

declare(strict_types=1);

namespace App\Service\Declaration\D394;

/**
 * Facts about the D394 form (declarația informativă privind livrările/prestările și
 * achizițiile efectuate pe teritoriul național) that ANAF's validator enforces.
 *
 * The lists were read from D394Validator.jar (Parameters_v7, d394validator/v5/*): the VAT
 * rates the form knows, the ISO country codes, the numeric county codes used by `judP`,
 * the operation types allowed per partner type and which rezumat1 attributes must exist
 * for a (tip_partener, cota) pair.
 */
final class D394Rules
{
    /** VAT rates (cota) the form accepts on op1 / rezumat2 rows. */
    public const RATES = [5, 9, 11, 19, 20, 21, 24];

    /** EU member states (plus Northern Ireland, which keeps an EU VAT number). */
    public const EU = ['AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'EL', 'GR', 'ES', 'FI', 'FR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'SE', 'SI', 'SK', 'XI'];

    /** Operation types per partner type (validator rules R215.1-3). */
    public const TYPES_BY_PARTNER = [
        1 => ['A', 'L', 'C', 'V', 'LS', 'AS', 'AI'],
        2 => ['L', 'LS', 'N'],
        3 => ['L', 'LS', 'C'],
        4 => ['L', 'LS', 'C'],
    ];

    /** Operation types that carry VAT (rule R232.1-2); the others must not have `tva`. */
    public const TYPES_WITH_VAT = ['A', 'L', 'C', 'AI'];

    /** Operation types whose cota must be 0 (rule R217). */
    public const ZERO_RATE_TYPES = ['LS', 'AS', 'N', 'V'];

    /**
     * `judP` is the numeric county code, not the two-letter one Storno stores on
     * clients and suppliers (B = 40, Călărași = 51, Giurgiu = 52).
     */
    public const COUNTY_CODES = [
        'AB' => '01', 'AR' => '02', 'AG' => '03', 'BC' => '04', 'BH' => '05', 'BN' => '06', 'BT' => '07', 'BV' => '08',
        'BR' => '09', 'BZ' => '10', 'CS' => '11', 'CJ' => '12', 'CT' => '13', 'CV' => '14', 'DB' => '15', 'DJ' => '16',
        'GL' => '17', 'GJ' => '18', 'HR' => '19', 'HD' => '20', 'IL' => '21', 'IS' => '22', 'IF' => '23', 'MM' => '24',
        'MH' => '25', 'MS' => '26', 'NT' => '27', 'OT' => '28', 'PH' => '29', 'SM' => '30', 'SJ' => '31', 'SB' => '32',
        'SV' => '33', 'TR' => '34', 'TM' => '35', 'TL' => '36', 'VS' => '37', 'VL' => '38', 'VN' => '39', 'B' => '40',
        'CL' => '51', 'GR' => '52',
    ];

    public static function isEu(string $country): bool
    {
        return in_array(strtoupper($country), self::EU, true);
    }

    /** Partner type: 1 RO VAT-registered, 2 RO not registered for VAT (or a private person), 3 EU, 4 outside the EU. */
    public static function partnerType(string $country, bool $vatPayer): int
    {
        $country = strtoupper($country ?: 'RO');
        if ($country === 'RO') {
            return $vatPayer ? 1 : 2;
        }
        return self::isEu($country) ? 3 : 4;
    }

    /** Numeric county code for `judP`, or null when the two-letter code is unknown. */
    public static function countyCode(?string $county): ?string
    {
        if ($county === null) {
            return null;
        }
        $county = strtoupper(trim($county));
        if (preg_match('/^(0[1-9]|[1-3]\d|40|51|52)$/', $county)) {
            return $county;
        }
        return self::COUNTY_CODES[$county] ?? null;
    }

    /** Romanian CUI checksum (weights 7 5 3 2 1 7 5 3 2 over the padded digits). */
    public static function isValidCui(string $cui): bool
    {
        $digits = preg_replace('/\D/', '', $cui) ?? '';
        if (strlen($digits) < 2 || strlen($digits) > 10) {
            return false;
        }
        $body = str_pad(substr($digits, 0, -1), 9, '0', STR_PAD_LEFT);
        $weights = [7, 5, 3, 2, 1, 7, 5, 3, 2];
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += ((int) $body[$i]) * $weights[$i];
        }
        $check = ($sum * 10) % 11;
        if ($check === 10) {
            $check = 0;
        }
        return $check === (int) substr($digits, -1);
    }

    /** Romanian CNP checksum (13 digits, weights 2 7 9 1 4 6 3 5 8 2 7 9). */
    public static function isValidCnp(string $cnp): bool
    {
        $digits = preg_replace('/\D/', '', $cnp) ?? '';
        if (strlen($digits) !== 13 || $digits[0] === '0') {
            return false;
        }
        $weights = [2, 7, 9, 1, 4, 6, 3, 5, 8, 2, 7, 9];
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += ((int) $digits[$i]) * $weights[$i];
        }
        $check = $sum % 11;
        if ($check === 10) {
            $check = 1;
        }
        return $check === (int) $digits[12];
    }

    /**
     * Which operation-type groups a rezumat1 row must carry for a (tip_partener, cota) pair.
     *
     * The validator does not look at which op1 rows exist: for a non-zero rate every
     * partner type needs the L attributes, type 1 also A and AI, types 1/3/4 also C; for
     * rate 0 types 1/3/4 need LS, type 1 also AS and V, and type 2 needs LS (with
     * document_N = 1) plus the N attributes. Groups that have no operations are written as 0.
     *
     * @return string[]
     */
    public static function rezumat1Groups(int $tipPartener, int $cota): array
    {
        if ($cota !== 0) {
            $groups = ['L'];
            if ($tipPartener === 1) {
                $groups[] = 'A';
                $groups[] = 'AI';
            }
            if (in_array($tipPartener, [1, 3, 4], true)) {
                $groups[] = 'C';
            }
            return $groups;
        }
        if ($tipPartener === 2) {
            return ['LS', 'N'];
        }
        $groups = ['LS'];
        if ($tipPartener === 1) {
            $groups[] = 'AS';
            $groups[] = 'V';
        }
        return $groups;
    }
}
