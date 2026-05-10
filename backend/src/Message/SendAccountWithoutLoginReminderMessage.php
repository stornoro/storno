<?php

namespace App\Message;

final readonly class SendAccountWithoutLoginReminderMessage
{
    public function __construct(
        public string $userId,
    ) {}
}
