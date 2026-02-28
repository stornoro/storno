<?php

namespace App\Enum;

enum EInvoiceSubmissionStatus: string
{
    case PENDING = 'pending';
    case SUBMITTED = 'submitted';
    case ACCEPTED = 'accepted';
    case REJECTED = 'rejected';
    case ERROR = 'error';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::ACCEPTED, self::REJECTED, self::ERROR => true,
            default => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'In asteptare',
            self::SUBMITTED => 'Trimisa',
            self::ACCEPTED => 'Acceptata',
            self::REJECTED => 'Respinsa',
            self::ERROR => 'Eroare',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::PENDING => 'gray',
            self::SUBMITTED => 'blue',
            self::ACCEPTED => 'green',
            self::REJECTED => 'red',
            self::ERROR => 'red',
        };
    }
}
