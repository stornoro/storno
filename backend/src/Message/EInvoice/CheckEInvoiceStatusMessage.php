<?php

namespace App\Message\EInvoice;

final readonly class CheckEInvoiceStatusMessage
{
    public function __construct(
        public string $submissionId,
        public int $attempt = 0,
    ) {}
}
