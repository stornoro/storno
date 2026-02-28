<?php

namespace App\Enum;

enum InvitationStatus: string
{
    case PENDING = 'pending';
    case ACCEPTED = 'accepted';
    case CANCELLED = 'cancelled';
    case EXPIRED = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'In asteptare',
            self::ACCEPTED => 'Acceptata',
            self::CANCELLED => 'Anulata',
            self::EXPIRED => 'Expirata',
        };
    }
}
