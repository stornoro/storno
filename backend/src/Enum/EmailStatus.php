<?php

namespace App\Enum;

enum EmailStatus: string
{
    case SENT = 'sent';
    case DELIVERED = 'delivered';
    case BOUNCED = 'bounced';
    case FAILED = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::SENT => 'Trimis',
            self::DELIVERED => 'Livrat',
            self::BOUNCED => 'Respins',
            self::FAILED => 'Esuat',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::SENT => 'blue',
            self::DELIVERED => 'green',
            self::BOUNCED => 'orange',
            self::FAILED => 'red',
        };
    }
}
