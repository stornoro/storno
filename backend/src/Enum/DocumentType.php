<?php

namespace App\Enum;

enum DocumentType: string
{
    case INVOICE = 'invoice';
    case CREDIT_NOTE = 'credit_note';
    case PROFORMA = 'proforma';

    public function label(): string
    {
        return match ($this) {
            self::INVOICE => 'Factura',
            self::CREDIT_NOTE => 'Nota de credit',
            self::PROFORMA => 'Proforma',
        };
    }
}
