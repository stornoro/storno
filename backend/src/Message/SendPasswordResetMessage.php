<?php

namespace App\Message;

final readonly class SendPasswordResetMessage
{
    public function __construct(
        public string $email,
        public string $token,
    ) {}
}
