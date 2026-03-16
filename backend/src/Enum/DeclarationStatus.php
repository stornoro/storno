<?php

namespace App\Enum;

enum DeclarationStatus: string
{
    case DRAFT = 'draft';
    case VALIDATED = 'validated';
    case SUBMITTED = 'submitted';
    case PROCESSING = 'processing';
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
            self::DRAFT => 'Ciorna',
            self::VALIDATED => 'Validata',
            self::SUBMITTED => 'Trimisa',
            self::PROCESSING => 'In procesare',
            self::ACCEPTED => 'Acceptata',
            self::REJECTED => 'Respinsa',
            self::ERROR => 'Eroare',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::DRAFT => 'gray',
            self::VALIDATED => 'blue',
            self::SUBMITTED => 'yellow',
            self::PROCESSING => 'orange',
            self::ACCEPTED => 'green',
            self::REJECTED => 'red',
            self::ERROR => 'red',
        };
    }
}
