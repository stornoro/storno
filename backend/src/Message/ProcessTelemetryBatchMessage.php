<?php

namespace App\Message;

final readonly class ProcessTelemetryBatchMessage
{
    public function __construct(
        public string $userId,
        public ?string $companyId,
        public array $events,
        public string $platform,
        public ?string $appVersion,
    ) {}
}
