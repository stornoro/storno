<?php

namespace App\Message;

final readonly class SendSubscriptionCancelledMessage
{
    public function __construct(
        public string $organizationId,
        public string $previousPlan,
    ) {}
}
