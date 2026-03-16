<?php

namespace App\Message\Declaration;

final readonly class SyncDeclarationsMessage
{
    public function __construct(
        public string $companyId,
        public int $year,
    ) {}
}
