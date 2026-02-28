<?php

namespace App\Message;

final readonly class CentrifugoPublishMessage
{
    public function __construct(
        public string $channel,
        public array $data,
    ) {}
}
