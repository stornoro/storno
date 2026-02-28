<?php

namespace App\Message;

final readonly class ProcessImportMessage
{
    public function __construct(
        public string $importJobId,
    ) {
    }
}
