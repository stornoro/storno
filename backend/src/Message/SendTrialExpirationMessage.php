<?php

namespace App\Message;

final readonly class SendTrialExpirationMessage
{
    public function __construct(
        public string $organizationId,
        public int $daysLeft,
    ) {}
}
