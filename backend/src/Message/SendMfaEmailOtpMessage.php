<?php

namespace App\Message;

final readonly class SendMfaEmailOtpMessage
{
    public function __construct(
        public string $email,
        public string $code,
    ) {}
}
