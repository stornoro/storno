<?php

namespace App\Enum;

enum InvoiceTypeCode: string
{
    case STANDARD = 'standard';
    case REVERSE_CHARGE = 'reverse_charge';
    case EXEMPT_WITH_DEDUCTION = 'exempt_with_deduction';
    case SERVICES_ART_311 = 'services_art_311';
    case SALES_ART_312 = 'sales_art_312';
    case NON_TAXABLE = 'non_taxable';
    case SPECIAL_REGIME_ART_314_315 = 'special_regime_art_314_315';
    case NON_TRANSFER = 'non_transfer';
    case SIMPLIFIED = 'simplified';
    case SERVICES_ART_278 = 'services_art_278';
    case EXEMPT_ART_294_AB = 'exempt_art_294_ab';
    case EXEMPT_ART_294_CD = 'exempt_art_294_cd';
    case SELF_BILLING = 'self_billing';

    public function label(): string
    {
        return match ($this) {
            self::STANDARD => 'Standard',
            self::REVERSE_CHARGE => 'Taxare inversa',
            self::EXEMPT_WITH_DEDUCTION => 'Scutit cu drept de deducere',
            self::SERVICES_ART_311 => 'Prestari servicii art. 311',
            self::SALES_ART_312 => 'Vanzari art. 312',
            self::NON_TAXABLE => 'Neimpozabil',
            self::SPECIAL_REGIME_ART_314_315 => 'Regim special art. 314-315',
            self::NON_TRANSFER => 'Non-transfer',
            self::SIMPLIFIED => 'Factura simplificata',
            self::SERVICES_ART_278 => 'Prestari servicii art. 278',
            self::EXEMPT_ART_294_AB => 'Scutit art. 294 alin. a-b',
            self::EXEMPT_ART_294_CD => 'Scutit art. 294 alin. c-d',
            self::SELF_BILLING => 'Autofacturare',
        };
    }

    /**
     * [BR-CL-01] UNTDID 1001 code for the XML InvoiceTypeCode element.
     * 380 = Commercial invoice, 389 = Self-billing invoice.
     */
    public function untdidCode(): string
    {
        return match ($this) {
            self::SELF_BILLING => '389',
            default => '380',
        };
    }
}
