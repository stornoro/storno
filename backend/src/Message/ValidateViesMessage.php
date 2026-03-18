<?php

namespace App\Message;

class ValidateViesMessage
{
    public function __construct(
        public readonly string $clientId,
        public readonly int $attempt = 1,
    ) {}
}
