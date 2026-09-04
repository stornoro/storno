<?php

namespace App\Enum;

/**
 * How loudly a new SPV document should be announced.
 *  - critical: push + email immediately, one notification per document (somatii, inactivation decisions, risk reports)
 *  - high:     push immediately, one notification per document (other decisions, notices, letters)
 *  - normal:   folded into the "N new documents" summary of the sync
 *  - low:      archived silently (receipts, declaration copies)
 */
enum SpvDocumentSeverity: string
{
    case CRITICAL = 'critical';
    case HIGH = 'high';
    case NORMAL = 'normal';
    case LOW = 'low';

    public function rank(): int
    {
        return match ($this) {
            self::CRITICAL => 3,
            self::HIGH => 2,
            self::NORMAL => 1,
            self::LOW => 0,
        };
    }
}
