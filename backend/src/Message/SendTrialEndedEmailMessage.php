<?php

namespace App\Message;

final readonly class SendTrialEndedEmailMessage
{
    public function __construct(
        public string $organizationId,
        public string $variant,
    ) {}
}
