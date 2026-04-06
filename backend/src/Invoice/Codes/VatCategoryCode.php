<?php

namespace App\Invoice\Codes;

class VatCategoryCode
{
    const STANDARD_RATE = 'S';
    const ZERO_RATED = 'Z';
    const EXEMPT_FROM_TAX = 'E';
    const REVERSE_CHARGE = 'AE';
    const EXEMPT_FOR_EXPORT = 'K';
    const INTRA_COMMUNITY_SUPPLY = 'L';
    const OUTSIDE_SCOPE = 'O';
    const MARGIN_SCHEME = 'M';

    public static function all(): array
    {
        return [
            self::STANDARD_RATE,
            self::ZERO_RATED,
            self::EXEMPT_FROM_TAX,
            self::REVERSE_CHARGE,
            self::EXEMPT_FOR_EXPORT,
            self::INTRA_COMMUNITY_SUPPLY,
            self::OUTSIDE_SCOPE,
            self::MARGIN_SCHEME,
        ];
    }

    public static function allWithMessageKeys(): array
    {
        return [
            ['code' => self::STANDARD_RATE, 'labelKey' => 'vatCategoryCodes.S.label', 'descriptionKey' => 'vatCategoryCodes.S.description'],
            ['code' => self::ZERO_RATED, 'labelKey' => 'vatCategoryCodes.Z.label', 'descriptionKey' => 'vatCategoryCodes.Z.description'],
            ['code' => self::EXEMPT_FROM_TAX, 'labelKey' => 'vatCategoryCodes.E.label', 'descriptionKey' => 'vatCategoryCodes.E.description'],
            ['code' => self::REVERSE_CHARGE, 'labelKey' => 'vatCategoryCodes.AE.label', 'descriptionKey' => 'vatCategoryCodes.AE.description'],
            ['code' => self::EXEMPT_FOR_EXPORT, 'labelKey' => 'vatCategoryCodes.K.label', 'descriptionKey' => 'vatCategoryCodes.K.description'],
            ['code' => self::INTRA_COMMUNITY_SUPPLY, 'labelKey' => 'vatCategoryCodes.L.label', 'descriptionKey' => 'vatCategoryCodes.L.description'],
            ['code' => self::OUTSIDE_SCOPE, 'labelKey' => 'vatCategoryCodes.O.label', 'descriptionKey' => 'vatCategoryCodes.O.description'],
            ['code' => self::MARGIN_SCHEME, 'labelKey' => 'vatCategoryCodes.M.label', 'descriptionKey' => 'vatCategoryCodes.M.description'],
        ];
    }
}
