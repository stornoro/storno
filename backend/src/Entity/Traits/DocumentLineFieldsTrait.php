<?php

namespace App\Entity\Traits;

use App\Doctrine\Type\UuidType;
use App\Entity\Product;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Shared ORM fields for all document line entities.
 *
 * Each entity using this trait must still declare its own parent relation
 * (e.g. $invoice, $proformaInvoice, $recurringInvoice).
 */
trait DocumentLineFieldsTrait
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['invoice:detail', 'proforma:detail', 'recurring_invoice:detail', 'delivery_note:detail', 'receipt:detail'])]
    private ?Uuid $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?Product $product = null;

    #[ORM\Column]
    #[Groups(['invoice:detail', 'proforma:detail', 'recurring_invoice:detail', 'delivery_note:detail', 'receipt:detail'])]
    private int $position = 0;

    #[ORM\Column(length: 500)]
    #[Groups(['invoice:detail', 'proforma:detail', 'recurring_invoice:detail', 'delivery_note:detail', 'receipt:detail'])]
    private ?string $description = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 4)]
    #[Groups(['invoice:detail', 'proforma:detail', 'recurring_invoice:detail', 'delivery_note:detail', 'receipt:detail'])]
    private string $quantity = '1.0000';

    #[ORM\Column(length: 20)]
    #[Groups(['invoice:detail', 'proforma:detail', 'recurring_invoice:detail', 'delivery_note:detail', 'receipt:detail'])]
    private string $unitOfMeasure = 'buc';

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    #[Groups(['invoice:detail', 'proforma:detail', 'recurring_invoice:detail', 'delivery_note:detail', 'receipt:detail'])]
    private string $unitPrice = '0.00';

    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2)]
    #[Groups(['invoice:detail', 'proforma:detail', 'recurring_invoice:detail', 'delivery_note:detail', 'receipt:detail'])]
    private string $vatRate = '21.00';

    #[ORM\Column(length: 10)]
    #[Groups(['invoice:detail', 'proforma:detail', 'recurring_invoice:detail', 'delivery_note:detail', 'receipt:detail'])]
    private string $vatCategoryCode = 'S';

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    #[Groups(['invoice:detail', 'proforma:detail', 'recurring_invoice:detail', 'delivery_note:detail', 'receipt:detail'])]
    private string $vatAmount = '0.00';

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    #[Groups(['invoice:detail', 'proforma:detail', 'recurring_invoice:detail', 'delivery_note:detail', 'receipt:detail'])]
    private string $lineTotal = '0.00';

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    #[Groups(['invoice:detail', 'proforma:detail', 'recurring_invoice:detail', 'delivery_note:detail', 'receipt:detail'])]
    private string $discount = '0.00';

    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2)]
    #[Groups(['invoice:detail', 'proforma:detail', 'recurring_invoice:detail', 'delivery_note:detail', 'receipt:detail'])]
    private string $discountPercent = '0.00';

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['invoice:detail', 'proforma:detail', 'recurring_invoice:detail', 'delivery_note:detail', 'receipt:detail'])]
    private bool $vatIncluded = false;

    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['invoice:detail', 'proforma:detail', 'recurring_invoice:detail', 'delivery_note:detail', 'receipt:detail'])]
    private ?string $productCode = null;

    #[ORM\Column(length: 500, nullable: true)]
    #[Groups(['invoice:detail', 'proforma:detail', 'recurring_invoice:detail', 'delivery_note:detail', 'receipt:detail'])]
    private ?string $lineNote = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['invoice:detail', 'proforma:detail', 'recurring_invoice:detail', 'delivery_note:detail', 'receipt:detail'])]
    private ?string $buyerAccountingRef = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['invoice:detail', 'proforma:detail', 'recurring_invoice:detail', 'delivery_note:detail', 'receipt:detail'])]
    private ?string $buyerItemIdentification = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['invoice:detail', 'proforma:detail', 'recurring_invoice:detail', 'delivery_note:detail', 'receipt:detail'])]
    private ?string $standardItemIdentification = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['invoice:detail', 'proforma:detail', 'recurring_invoice:detail', 'delivery_note:detail', 'receipt:detail'])]
    private ?string $cpvCode = null;

    private function initId(): void
    {
        $this->id = Uuid::v7();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getProduct(): ?Product
    {
        return $this->product;
    }

    public function setProduct(?Product $product): static
    {
        $this->product = $product;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getQuantity(): string
    {
        return $this->quantity;
    }

    public function setQuantity(string $quantity): static
    {
        $this->quantity = $quantity;

        return $this;
    }

    public function getUnitOfMeasure(): string
    {
        return $this->unitOfMeasure;
    }

    public function setUnitOfMeasure(string $unitOfMeasure): static
    {
        $this->unitOfMeasure = $unitOfMeasure;

        return $this;
    }

    public function getUnitPrice(): string
    {
        return $this->unitPrice;
    }

    public function setUnitPrice(string $unitPrice): static
    {
        $this->unitPrice = $unitPrice;

        return $this;
    }

    public function getVatRate(): string
    {
        return $this->vatRate;
    }

    public function setVatRate(string $vatRate): static
    {
        // Normalize to DECIMAL(5,2) format: "21" → "21.00", "9.5" → "9.50"
        $this->vatRate = number_format((float) $vatRate, 2, '.', '');

        return $this;
    }

    public function getVatCategoryCode(): string
    {
        return $this->vatCategoryCode;
    }

    public function setVatCategoryCode(string $vatCategoryCode): static
    {
        $this->vatCategoryCode = $vatCategoryCode;

        return $this;
    }

    public function getVatAmount(): string
    {
        return $this->vatAmount;
    }

    public function setVatAmount(string $vatAmount): static
    {
        $this->vatAmount = $vatAmount;

        return $this;
    }

    public function getLineTotal(): string
    {
        return $this->lineTotal;
    }

    public function setLineTotal(string $lineTotal): static
    {
        $this->lineTotal = $lineTotal;

        return $this;
    }

    public function getDiscount(): string
    {
        return $this->discount;
    }

    public function setDiscount(string $discount): static
    {
        $this->discount = $discount;

        return $this;
    }

    public function getDiscountPercent(): string
    {
        return $this->discountPercent;
    }

    public function setDiscountPercent(string $discountPercent): static
    {
        $this->discountPercent = $discountPercent;

        return $this;
    }

    public function isVatIncluded(): bool
    {
        return $this->vatIncluded;
    }

    public function setVatIncluded(bool $vatIncluded): static
    {
        $this->vatIncluded = $vatIncluded;

        return $this;
    }

    public function getProductCode(): ?string
    {
        return $this->productCode;
    }

    public function setProductCode(?string $productCode): static
    {
        $this->productCode = $productCode;

        return $this;
    }

    public function getLineNote(): ?string
    {
        return $this->lineNote;
    }

    public function setLineNote(?string $lineNote): static
    {
        $this->lineNote = $lineNote;

        return $this;
    }

    public function getBuyerAccountingRef(): ?string
    {
        return $this->buyerAccountingRef;
    }

    public function setBuyerAccountingRef(?string $buyerAccountingRef): static
    {
        $this->buyerAccountingRef = $buyerAccountingRef;

        return $this;
    }

    public function getBuyerItemIdentification(): ?string
    {
        return $this->buyerItemIdentification;
    }

    public function setBuyerItemIdentification(?string $buyerItemIdentification): static
    {
        $this->buyerItemIdentification = $buyerItemIdentification;

        return $this;
    }

    public function getStandardItemIdentification(): ?string
    {
        return $this->standardItemIdentification;
    }

    public function setStandardItemIdentification(?string $standardItemIdentification): static
    {
        $this->standardItemIdentification = $standardItemIdentification;

        return $this;
    }

    public function getCpvCode(): ?string
    {
        return $this->cpvCode;
    }

    public function setCpvCode(?string $cpvCode): static
    {
        $this->cpvCode = $cpvCode;

        return $this;
    }
}
