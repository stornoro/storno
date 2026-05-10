<?php

namespace App\Message;

final readonly class SendFirstCompanyCreatedEmailMessage
{
    public function __construct(
        public string $organizationId,
    ) {}
}
