<?php

declare(strict_types=1);

namespace App\Service\Declaration\Saft;

use App\Service\Anaf\UblXmlGenerator;

/**
 * SAF-T nomenclature lookups: the VAT tax codes (TaxTable / TaxInformation, tax type 300),
 * the "no tax" pair ANAF prescribes for lines that carry no tax (000 / 000000) and the units
 * of measure of the UOMTable. The lists live in resources/saft/*.json (see the README there
 * for the ANAF source).
 */
final class SaftTaxCodes
{
    public const TAX_TYPE_VAT = '300';
    public const TAX_TYPE_NONE = '000';
    public const TAX_CODE_NONE = '000000';

    /** Sales operation kinds resolved from the invoice line / invoice type. */
    public const SALE_STANDARD = 'standard';
    public const SALE_VAT_ON_COLLECTION = 'vatOnCollection';
    public const SALE_REVERSE_CHARGE = 'reverseCharge';
    public const SALE_INTRA_COMMUNITY_GOODS = 'intraCommunityGoods';
    public const SALE_INTRA_COMMUNITY_SERVICES = 'intraCommunityServices';
    public const SALE_EXPORT = 'export';
    public const SALE_EXEMPT_WITH_DEDUCTION = 'exemptWithDeduction';
    public const SALE_EXEMPT_WITHOUT_DEDUCTION = 'exemptWithoutDeduction';
    public const SALE_OUTSIDE_SCOPE = 'outsideScope';
    public const SALE_SERVICES_OUTSIDE_EU = 'servicesOutsideEu';
    public const SALE_SPECIAL_REGIME_OSS = 'specialRegimeOss';

    public const PURCHASE_STANDARD = 'standard';
    public const PURCHASE_VAT_ON_COLLECTION = 'vatOnCollection';
    public const PURCHASE_REVERSE_CHARGE = 'reverseCharge';
    public const PURCHASE_INTRA_COMMUNITY_GOODS = 'intraCommunityGoods';
    public const PURCHASE_INTRA_COMMUNITY_SERVICES = 'intraCommunityServices';
    public const PURCHASE_IMPORT = 'import';
    public const PURCHASE_EXEMPT = 'exempt';

    /** @var array<string, mixed>|null */
    private static ?array $codes = null;
    /** @var array<string, string>|null */
    private static ?array $uom = null;

    /** The tax code of a sale, or null when the kind / rate has no code in the nomenclature. */
    public static function sale(string $kind, int $rate): ?string
    {
        $entry = self::codes()['sales'][$kind] ?? null;

        return self::pick($entry, $rate);
    }

    /** The tax code of a purchase; companies not registered for VAT use the non-deductible codes. */
    public static function purchase(string $kind, int $rate, bool $registeredForVat): ?string
    {
        $table = self::codes()[$registeredForVat ? 'purchases' : 'purchasesNotRegistered'];
        $entry = $table[$kind] ?? null;
        if ($entry === null && !$registeredForVat) {
            // the non-deductible sheet has no separate reverse-charge / VAT-on-collection / import codes
            $entry = $table[self::PURCHASE_STANDARD];
        }

        return self::pick($entry, $rate);
    }

    public static function description(string $code): string
    {
        if ($code === self::TAX_CODE_NONE) {
            return (string) self::codes()['none']['description'];
        }

        return (string) (self::codes()['descriptions'][$code] ?? ('Cod de taxa ' . $code));
    }

    /** Rates the nomenclature knows for a rate-keyed entry. */
    public static function knownRates(): array
    {
        return [21, 19, 11, 9, 5];
    }

    /** UN/ECE unit code for an invoice line's unit label (H87 when unknown). */
    public static function unitCode(?string $unit): string
    {
        $unit = trim((string) $unit);
        if ($unit === '') {
            return 'H87';
        }
        $key = mb_strtolower($unit);
        if (isset(UblXmlGenerator::UNIT_CODES[$key])) {
            return UblXmlGenerator::UNIT_CODES[$key];
        }
        $upper = strtoupper($unit);
        $aliases = ['PK' => 'XPK', 'C62' => 'H87', 'EA' => 'H87'];
        if (isset($aliases[$upper])) {
            return $aliases[$upper];
        }
        if (isset(self::uom()[$upper])) {
            return $upper;
        }

        return 'H87';
    }

    public static function unitDescription(string $code): string
    {
        return self::uom()[$code] ?? $code;
    }

    /** @param array<string, string>|string|null $entry */
    private static function pick(array|string|null $entry, int $rate): ?string
    {
        if ($entry === null) {
            return null;
        }
        if (is_string($entry)) {
            return $entry;
        }

        return $entry[(string) $rate] ?? null;
    }

    /** @return array<string, mixed> */
    private static function codes(): array
    {
        if (self::$codes === null) {
            self::$codes = json_decode((string) file_get_contents(self::dir() . '/tax_codes.json'), true, 512, JSON_THROW_ON_ERROR);
        }

        return self::$codes;
    }

    /** @return array<string, string> */
    private static function uom(): array
    {
        if (self::$uom === null) {
            $all = json_decode((string) file_get_contents(self::dir() . '/uom.json'), true, 512, JSON_THROW_ON_ERROR);
            unset($all['_comment']);
            self::$uom = $all;
        }

        return self::$uom;
    }

    private static function dir(): string
    {
        return __DIR__ . '/../../../../resources/saft';
    }
}
