<?php

namespace App\Message;

final readonly class DispatchWebhookMessage
{
    public function __construct(
        public string $endpointId,
        public string $eventType,
        public array $payload,
        public int $attempt = 1,
        public ?string $deliveryId = null,
    ) {}
}
