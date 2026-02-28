<?php

namespace App\Invoice\Payment;

use App\Invoice\Amount;
use App\Invoice\Schema;
use JMS\Serializer\Annotation\{Type, XmlAttribute, XmlNamespace, SerializedName, XmlRoot, XmlElement, XmlList};

class Price
{
    #[SerializedName("PriceAmount")]
    #[XmlElement(cdata: false, namespace: Schema::CBC)]
    #[Type(Amount::class)]
    private Amount $priceAmount;

    #[SerializedName("BaseQuantity")]
    #[XmlElement(cdata: false, namespace: Schema::CBC)]
    #[Type("integer")]
    private $baseQuantity;

    /**
     * The price of an item, exclusive of VAT, after subtracting item price discount.
     *  Example value: 23.45
     */
    public function getPriceAmount(): Amount
    {
        return $this->priceAmount;
    }

    /**
     * Set price amount
     */
    public function setPriceAmount(Amount $priceAmount): Price
    {
        $this->priceAmount = $priceAmount;
        return $this;
    }

    /**
     * The number of item units to which the price applies.
     * Example value: 1
     */
    public function getBaseQuantity(): ?float
    {
        return $this->baseQuantity;
    }

    /**
     * Set base quantity
     */
    public function setBaseQuantity(?float $baseQuantity): Price
    {
        $this->baseQuantity = $baseQuantity;
        return $this;
    }
}
