<?php

namespace App\Message\Anaf;

final readonly class SyncCompanyMessage
{
    public function __construct(
        public string $companyId,
        public ?int $daysOverride = null,
    ) {
    }
}
