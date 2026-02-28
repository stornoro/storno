<?php

namespace App\Message;

final readonly class SendDunningEmailMessage
{
    public function __construct(
        public string $organizationId,
        public int $attempt,
    ) {}
}
