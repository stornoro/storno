<?php

namespace App\Message;

final readonly class SendPlanChangedMessage
{
    public function __construct(
        public string $organizationId,
        public string $oldPlan,
        public string $newPlan,
    ) {}
}
