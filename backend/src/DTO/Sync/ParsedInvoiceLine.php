<?php

namespace App\DTO\Sync;

class ParsedInvoiceLine
{
    public function __construct(
        public readonly string $description = '',
        public readonly string $quantity = '1',
        public readonly string $unitOfMeasure = 'buc',
        public readonly string $unitPrice = '0.00',
        public readonly string $vatRate = '21.00',
        public readonly string $vatCategoryCode = 'S',
        public readonly string $vatAmount = '0.00',
        public readonly string $lineTotal = '0.00',
    ) {}
}
