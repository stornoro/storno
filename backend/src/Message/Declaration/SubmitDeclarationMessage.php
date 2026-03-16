<?php

namespace App\Message\Declaration;

final readonly class SubmitDeclarationMessage
{
    public function __construct(
        public string $declarationId,
    ) {}
}
