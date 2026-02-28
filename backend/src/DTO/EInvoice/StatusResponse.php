<?php

namespace App\DTO\EInvoice;

use App\Enum\EInvoiceSubmissionStatus;

final readonly class StatusResponse
{
    public function __construct(
        public EInvoiceSubmissionStatus $status,
        public ?string $errorMessage = null,
        public array $metadata = [],
    ) {}
}
