<?php

namespace App\Message;

final readonly class GeneratePdfMessage
{
    public function __construct(
        public string $invoiceId,
    ) {
    }
}
