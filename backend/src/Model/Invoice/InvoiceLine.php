<?php

namespace App\Model\Invoice;


class InvoiceLine implements \JsonSerializable
{
    private string $productName;
    private float $productPrice;
    private int $quantity;
    private int $vatPercent;
    private ?string $productIdentifier = null;


    /**
     * Get the value of productName
     *
     * @return string
     */
    public function getProductName(): string
    {
        return $this->productName;
    }

    /**
     * Set the value of productName
     *
     * @param string $productName
     *
     * @return self
     */
    public function setProductName(string $productName): self
    {
        $this->productName = $productName;

        return $this;
    }

    /**
     * Get the value of productPrice
     *
     * @return float
     */
    public function getProductPrice(): float
    {
        return $this->productPrice;
    }

    /**
     * Set the value of productPrice
     *
     * @param float $productPrice
     *
     * @return self
     */
    public function setProductPrice(float $productPrice): self
    {
        $this->productPrice = $productPrice;

        return $this;
    }

    /**
     * Get the value of quantity
     *
     * @return int
     */
    public function getQuantity(): int
    {
        return $this->quantity;
    }

    /**
     * Set the value of quantity
     *
     * @param int $quantity
     *
     * @return self
     */
    public function setQuantity(int $quantity): self
    {
        $this->quantity = $quantity;

        return $this;
    }

    /**
     * Get the value of vatPercent
     *
     * @return int
     */
    public function getVatPercent(): int
    {
        return $this->vatPercent;
    }

    /**
     * Set the value of vatPercent
     *
     * @param int $vatPercent
     *
     * @return self
     */
    public function setVatPercent(int $vatPercent): self
    {
        $this->vatPercent = $vatPercent;

        return $this;
    }

    /**
     * Get the value of productIdentifier
     *
     * @return string
     */
    public function getProductIdentifier(): ?string
    {
        return $this->productIdentifier;
    }

    /**
     * Set the value of productIdentifier
     *
     * @param ?string $productIdentifier
     *
     * @return self
     */
    public function setProductIdentifier(?string $productIdentifier): self
    {
        $this->productIdentifier = $productIdentifier;

        return $this;
    }
    public function jsonSerialize()
    {
        return [
            'product_name' => $this->productName,
            'product_price' => $this->productPrice,
            'quantity' => $this->quantity,
            'vat_percent' => $this->vatPercent,
            'product_identifier' => $this->productIdentifier
        ];
    }
}
