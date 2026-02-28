<?php

namespace App\Message\Anaf;

final readonly class SubmitETransportMessage
{
    public function __construct(
        public string $deliveryNoteId,
    ) {
    }
}
