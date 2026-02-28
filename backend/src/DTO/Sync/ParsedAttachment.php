<?php

namespace App\DTO\Sync;

class ParsedAttachment
{
    public function __construct(
        public readonly ?string $filename = null,
        public readonly ?string $mimeType = null,
        public readonly ?string $description = null,
        public readonly ?string $content = null, // base64-encoded
    ) {}
}
