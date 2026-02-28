<?php

namespace App\Message;

final readonly class GenerateZipExportMessage
{
    public function __construct(
        public string $companyId,
        public array $invoiceIds,
        public string $userId,
    ) {
    }
}
