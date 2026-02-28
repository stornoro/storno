<?php

namespace App\DTO\Anaf;

final readonly class ETransportStatusResponse
{
    public function __construct(
        public string $status,
        public ?string $uit = null,
        public ?string $errorMessage = null,
    ) {
    }

    public static function fromResponse(array $data): self
    {
        return new self(
            status: (string) ($data['stare'] ?? 'unknown'),
            uit: $data['UIT'] ?? $data['uit'] ?? null,
            errorMessage: $data['eroare'] ?? $data['error'] ?? null,
        );
    }

    public function isPending(): bool
    {
        return $this->status === 'in prelucrare';
    }

    public function isOk(): bool
    {
        return $this->status === 'ok' && $this->uit !== null;
    }

    public function isError(): bool
    {
        return $this->status === 'nok' || ($this->errorMessage !== null && !$this->isPending());
    }
}
