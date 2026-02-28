<?php

namespace App\Enum;

enum ProformaStatus: string
{
    case DRAFT = 'draft';
    case SENT = 'sent';
    case ACCEPTED = 'accepted';
    case REJECTED = 'rejected';
    case CONVERTED = 'converted';
    case CANCELLED = 'cancelled';
    case EXPIRED = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Ciorna',
            self::SENT => 'Trimisa',
            self::ACCEPTED => 'Acceptata',
            self::REJECTED => 'Respinsa',
            self::CONVERTED => 'Convertita',
            self::CANCELLED => 'Anulata',
            self::EXPIRED => 'Expirata',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::DRAFT => 'gray',
            self::SENT => 'blue',
            self::ACCEPTED => 'green',
            self::REJECTED => 'red',
            self::CONVERTED => 'purple',
            self::CANCELLED => 'gray',
            self::EXPIRED => 'orange',
        };
    }
}
