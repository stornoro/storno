<?php

namespace App\Message;

final readonly class SendFeatureHighlightDripMessage
{
    public function __construct(
        public string $organizationId,
        public string $feature,
        public int $day,
    ) {}
}
