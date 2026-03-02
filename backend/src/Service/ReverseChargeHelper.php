<?php

namespace App\Service;

use App\Entity\Client;
use App\Entity\Company;

class ReverseChargeHelper
{
    private const EU_COUNTRY_CODES = [
        'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'EL', 'ES',
        'FI', 'FR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT',
        'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK',
    ];

    /**
     * Determine if reverse charge (intra-community) should apply.
     *
     * Rules:
     * - Client must be foreign (country != RO)
     * - Client must have a valid VIES registration
     * - Selling company must have an EU VAT registration (vatIn or vatCode starting with EU prefix)
     */
    public static function shouldApplyReverseCharge(Client $client, Company $company): bool
    {
        // Romanian clients never get reverse charge
        if ($client->getCountry() === 'RO') {
            return false;
        }

        // Client must have valid VIES
        if ($client->isViesValid() !== true) {
            return false;
        }

        // Company must have EU VAT registration
        if (!self::companyHasEuVat($company)) {
            return false;
        }

        return true;
    }

    private static function companyHasEuVat(Company $company): bool
    {
        // Check explicit vatIn field
        $vatIn = $company->getVatIn();
        if ($vatIn !== null && $vatIn !== '') {
            return true;
        }

        // Check if vatCode starts with an EU country prefix
        $vatCode = $company->getVatCode();
        if ($vatCode !== null && $vatCode !== '') {
            $prefix = strtoupper(substr($vatCode, 0, 2));
            if (in_array($prefix, self::EU_COUNTRY_CODES, true)) {
                return true;
            }
        }

        return false;
    }
}
