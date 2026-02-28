<?php

namespace App\DTO\Sync;

use App\Enum\DocumentType;

class ParsedInvoice
{
    /**
     * @param ParsedInvoiceLine[] $lines
     * @param ParsedAttachment[] $attachments
     */
    public function __construct(
        public readonly ?string $number = null,
        public readonly ?string $issueDate = null,
        public readonly ?string $dueDate = null,
        public readonly string $currency = 'RON',
        public readonly string $subtotal = '0.00',
        public readonly string $vatTotal = '0.00',
        public readonly string $total = '0.00',
        public readonly DocumentType $documentType = DocumentType::INVOICE,
        public readonly ?string $notes = null,
        public readonly ?string $paymentTerms = null,
        public readonly ?ParsedParty $seller = null,
        public readonly ?ParsedParty $buyer = null,
        public readonly array $lines = [],
        public readonly ?string $deliveryLocation = null,
        public readonly ?string $projectReference = null,
        public readonly array $attachments = [],
    ) {}
}
