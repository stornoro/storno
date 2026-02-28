<?php

namespace App\DTO\Anaf;

final readonly class EFacturaStatusResponse
{
    public function __construct(
        public ?string $downloadId,
        public string $status,
        public ?string $errorMessage = null,
    ) {
    }

    public static function fromResponse(array $data): self
    {
        return new self(
            downloadId: isset($data['id_descarcare']) ? (string) $data['id_descarcare'] : null,
            status: (string) ($data['stare'] ?? 'unknown'),
            errorMessage: $data['eroare'] ?? $data['error'] ?? null,
        );
    }

    public function isPending(): bool
    {
        return $this->status === 'in prelucrare';
    }

    public function isOk(): bool
    {
        return $this->status === 'ok' && $this->downloadId !== null;
    }

    public function isError(): bool
    {
        return $this->status === 'nok' || $this->errorMessage !== null;
    }
}
