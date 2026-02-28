<?php

namespace App\Message\EInvoice;

final readonly class SubmitEInvoiceMessage
{
    public function __construct(
        public string $invoiceId,
        public string $provider,
    ) {}
}
