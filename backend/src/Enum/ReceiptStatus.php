<?php

namespace App\Enum;

enum ReceiptStatus: string
{
    case DRAFT = 'draft';
    case ISSUED = 'issued';
    case INVOICED = 'invoiced';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Ciorna',
            self::ISSUED => 'Emis',
            self::INVOICED => 'Facturat',
            self::CANCELLED => 'Anulat',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::DRAFT => 'gray',
            self::ISSUED => 'blue',
            self::INVOICED => 'purple',
            self::CANCELLED => 'gray',
        };
    }
}
