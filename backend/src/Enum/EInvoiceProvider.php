<?php

namespace App\Enum;

enum EInvoiceProvider: string
{
    case ANAF = 'anaf';
    case XRECHNUNG = 'xrechnung';
    case SDI = 'sdi';
    case KSEF = 'ksef';
    case FACTURX = 'facturx';

    public function label(): string
    {
        return match ($this) {
            self::ANAF => 'e-Factura ANAF (Romania)',
            self::XRECHNUNG => 'XRechnung (Germany)',
            self::SDI => 'SDI (Italy)',
            self::KSEF => 'KSeF (Poland)',
            self::FACTURX => 'Factur-X (France)',
        };
    }

    public function country(): string
    {
        return match ($this) {
            self::ANAF => 'RO',
            self::XRECHNUNG => 'DE',
            self::SDI => 'IT',
            self::KSEF => 'PL',
            self::FACTURX => 'FR',
        };
    }
}
