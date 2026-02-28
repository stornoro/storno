<?php

namespace App\DTO\Anaf;

final readonly class EFacturaUploadResponse
{
    public function __construct(
        public string $uploadId,
        public bool $success,
        public ?string $errorMessage = null,
    ) {
    }

    public static function fromResponse(array $data): self
    {
        $success = isset($data['index_incarcare']) && !empty($data['index_incarcare']);

        return new self(
            uploadId: (string) ($data['index_incarcare'] ?? ''),
            success: $success,
            errorMessage: $data['eroare'] ?? $data['error'] ?? null,
        );
    }
}
