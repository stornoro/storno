<?php

namespace App\Message\Anaf;

final readonly class CheckETransportStatusMessage
{
    public function __construct(
        public string $deliveryNoteId,
        public int $attempt = 0,
    ) {
    }
}
