<?php

namespace App\Entity;

use Symfony\Component\Uid\Uuid;

/**
 * Common contract for all document line entities (InvoiceLine, ProformaInvoiceLine, RecurringInvoiceLine).
 */
interface DocumentLineInterface
{
    public function getId(): ?Uuid;

    public function getProduct(): ?Product;
    public function setProduct(?Product $product): static;

    public function getPosition(): int;
    public function setPosition(int $position): static;

    public function getDescription(): ?string;
    public function setDescription(string $description): static;

    public function getQuantity(): string;
    public function setQuantity(string $quantity): static;

    public function getUnitOfMeasure(): string;
    public function setUnitOfMeasure(string $unitOfMeasure): static;

    public function getUnitPrice(): string;
    public function setUnitPrice(string $unitPrice): static;

    public function getVatRate(): string;
    public function setVatRate(string $vatRate): static;

    public function getVatCategoryCode(): string;
    public function setVatCategoryCode(string $vatCategoryCode): static;

    public function getVatAmount(): string;
    public function setVatAmount(string $vatAmount): static;

    public function getLineTotal(): string;
    public function setLineTotal(string $lineTotal): static;

    public function getDiscount(): string;
    public function setDiscount(string $discount): static;

    public function getDiscountPercent(): string;
    public function setDiscountPercent(string $discountPercent): static;

    public function isVatIncluded(): bool;
    public function setVatIncluded(bool $vatIncluded): static;

    public function getProductCode(): ?string;
    public function setProductCode(?string $productCode): static;

    public function getLineNote(): ?string;
    public function setLineNote(?string $lineNote): static;

    public function getBuyerAccountingRef(): ?string;
    public function setBuyerAccountingRef(?string $buyerAccountingRef): static;

    public function getBuyerItemIdentification(): ?string;
    public function setBuyerItemIdentification(?string $buyerItemIdentification): static;

    public function getStandardItemIdentification(): ?string;
    public function setStandardItemIdentification(?string $standardItemIdentification): static;

    public function getCpvCode(): ?string;
    public function setCpvCode(?string $cpvCode): static;
}
