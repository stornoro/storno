<?php

namespace App\DTO\Anaf;

final readonly class ValidationError
{
    public function __construct(
        public string $message,
        public string $source = 'business',
        public ?string $ruleId = null,
        public ?string $location = null,
    ) {}

    public function toArray(): array
    {
        return array_filter([
            'message' => $this->message,
            'source' => $this->source,
            'ruleId' => $this->ruleId,
            'location' => $this->location,
        ], fn ($v) => $v !== null);
    }
}
