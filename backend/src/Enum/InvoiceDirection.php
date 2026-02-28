<?php

namespace App\Enum;

enum InvoiceDirection: string
{
    case INCOMING = 'incoming';
    case OUTGOING = 'outgoing';

    public function label(): string
    {
        return match ($this) {
            self::INCOMING => 'Primita',
            self::OUTGOING => 'Trimisa',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::INCOMING => 'blue',
            self::OUTGOING => 'green',
        };
    }
}
