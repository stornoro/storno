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
        public readonly ?array $ublExtensions = null,
        /** BT-157 StandardItemIdentification (EAN/GTIN when schemeID 0160) */
        public readonly ?string $barcode = null,
        /** BT-155 SellersItemIdentification: the supplier's own article code */
        public readonly ?string $sellerItemCode = null,
        /** BT-156 BuyersItemIdentification: our article code as the supplier knows it */
        public readonly ?string $buyerItemCode = null,
    ) {}

    /** Same line with a corrected VAT amount (used when the document total is repartitioned). */
    public function withVatAmount(string $vatAmount): self
    {
        return new self(
            description: $this->description,
            quantity: $this->quantity,
            unitOfMeasure: $this->unitOfMeasure,
            unitPrice: $this->unitPrice,
            vatRate: $this->vatRate,
            vatCategoryCode: $this->vatCategoryCode,
            vatAmount: $vatAmount,
            lineTotal: $this->lineTotal,
            ublExtensions: $this->ublExtensions,
            barcode: $this->barcode,
            sellerItemCode: $this->sellerItemCode,
            buyerItemCode: $this->buyerItemCode,
        );
    }
}
