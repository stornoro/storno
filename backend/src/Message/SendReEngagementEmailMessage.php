<?php

namespace App\Message;

final readonly class SendReEngagementEmailMessage
{
    public function __construct(
        public string $userId,
    ) {}
}
