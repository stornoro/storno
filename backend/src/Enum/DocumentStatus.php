<?php

namespace App\Enum;

enum DocumentStatus: string
{
    case DRAFT = 'draft';
    case SYNCED = 'synced';
    case ISSUED = 'issued';
    case SENT_TO_PROVIDER = 'sent_to_provider';
    case VALIDATED = 'validated';
    case REJECTED = 'rejected';
    case CANCELLED = 'cancelled';
    case PAID = 'paid';
    case PARTIALLY_PAID = 'partially_paid';
    case OVERDUE = 'overdue';
    case CONVERTED = 'converted';
    case REFUND = 'refund';
    case REFUNDED = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Ciorna',
            self::SYNCED => 'Sincronizata',
            self::ISSUED => 'Emisa',
            self::SENT_TO_PROVIDER => 'Trimisa in SPV',
            self::VALIDATED => 'Validata',
            self::REJECTED => 'Respinsa',
            self::CANCELLED => 'Anulata',
            self::PAID => 'Platita',
            self::PARTIALLY_PAID => 'Partial platita',
            self::OVERDUE => 'Scadenta',
            self::CONVERTED => 'Convertita',
            self::REFUND => 'Rambursare',
            self::REFUNDED => 'Rambursata',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::DRAFT => 'gray',
            self::SYNCED => 'cyan',
            self::ISSUED => 'blue',
            self::SENT_TO_PROVIDER => 'yellow',
            self::VALIDATED => 'green',
            self::REJECTED => 'red',
            self::CANCELLED => 'gray',
            self::PAID => 'green',
            self::PARTIALLY_PAID => 'orange',
            self::OVERDUE => 'red',
            self::CONVERTED => 'purple',
            self::REFUND => 'orange',
            self::REFUNDED => 'red',
        };
    }
}
