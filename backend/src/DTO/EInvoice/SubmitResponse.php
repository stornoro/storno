<?php

namespace App\DTO\EInvoice;

final readonly class SubmitResponse
{
    public function __construct(
        public bool $success,
        public ?string $externalId = null,
        public ?string $errorMessage = null,
        public array $metadata = [],
    ) {}
}
