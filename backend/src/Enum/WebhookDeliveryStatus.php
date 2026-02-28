<?php

namespace App\Enum;

enum WebhookDeliveryStatus: string
{
    case PENDING = 'pending';
    case SUCCESS = 'success';
    case FAILED = 'failed';
    case RETRYING = 'retrying';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'In asteptare',
            self::SUCCESS => 'Livrat',
            self::FAILED => 'Esuat',
            self::RETRYING => 'Reincercare',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::PENDING => 'blue',
            self::SUCCESS => 'green',
            self::FAILED => 'red',
            self::RETRYING => 'orange',
        };
    }
}
