<?php

namespace App\Exception;

/**
 * Thrown by OutboundEmailGuard when a document email must not be sent
 * (plan restriction, rate limit, recipient cap, or blocked content).
 */
class EmailSendBlockedException extends \RuntimeException
{
    public const CODE_PLAN_LIMIT = 'PLAN_LIMIT';
    public const CODE_RATE_LIMIT = 'EMAIL_RATE_LIMIT';
    public const CODE_DAILY_LIMIT = 'EMAIL_DAILY_LIMIT';
    public const CODE_TOO_MANY_RECIPIENTS = 'EMAIL_TOO_MANY_RECIPIENTS';
    public const CODE_CONTENT_BLOCKED = 'EMAIL_CONTENT_BLOCKED';

    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $httpStatus,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message);
    }
}
