<?php

namespace App\Message;

class SendEmailConfirmationMessage
{
    public function __construct(
        public readonly string $userId,
    ) {}
}
