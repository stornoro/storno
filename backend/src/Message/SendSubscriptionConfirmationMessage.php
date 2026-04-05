<?php

namespace App\Message;

final readonly class SendSubscriptionConfirmationMessage
{
    public function __construct(
        public string $organizationId,
        public string $planName,
        public int $amount,
        public string $currency,
        public string $interval,
        public ?string $licenseKey = null,
    ) {}
}
