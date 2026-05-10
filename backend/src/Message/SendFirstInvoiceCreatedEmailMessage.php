<?php

namespace App\Message;

final readonly class SendFirstInvoiceCreatedEmailMessage
{
    public function __construct(
        public string $organizationId,
    ) {}
}
