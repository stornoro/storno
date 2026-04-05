<?php

namespace App\Message;

final readonly class SendSubscriptionRenewalMessage
{
    public function __construct(
        public string $organizationId,
        public string $planName,
        public int $amount,
        public string $currency,
        public string $interval,
    ) {}
}
