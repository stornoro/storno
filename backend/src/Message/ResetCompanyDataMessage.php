<?php

namespace App\Message;

final readonly class ResetCompanyDataMessage
{
    public function __construct(
        public string $companyId,
    ) {
    }
}
