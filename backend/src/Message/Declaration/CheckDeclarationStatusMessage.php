<?php

namespace App\Message\Declaration;

final readonly class CheckDeclarationStatusMessage
{
    public function __construct(
        public string $declarationId,
        public int $attempt = 0,
    ) {}
}
