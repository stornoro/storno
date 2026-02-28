<?php

namespace App\Message;

final readonly class DeleteCompanyDataMessage
{
    public function __construct(
        public string $companyId,
    ) {
    }
}
