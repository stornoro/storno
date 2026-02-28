<?php

namespace App\Enum;

enum DeliveryNoteStatus: string
{
    case DRAFT = 'draft';
    case ISSUED = 'issued';
    case CONVERTED = 'converted';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Ciorna',
            self::ISSUED => 'Emis',
            self::CONVERTED => 'Facturat',
            self::CANCELLED => 'Anulat',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::DRAFT => 'gray',
            self::ISSUED => 'blue',
            self::CONVERTED => 'purple',
            self::CANCELLED => 'gray',
        };
    }
}
