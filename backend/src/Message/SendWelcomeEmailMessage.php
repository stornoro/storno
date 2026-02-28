<?php

namespace App\Message;

final readonly class SendWelcomeEmailMessage
{
    public function __construct(
        public string $userId,
    ) {}
}
