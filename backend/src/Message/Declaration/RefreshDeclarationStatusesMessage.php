<?php

namespace App\Message\Declaration;

final readonly class RefreshDeclarationStatusesMessage
{
    public function __construct(
        public string $companyId,
    ) {}
}
