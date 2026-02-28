<?php

namespace App\Invoice\Account;

use App\Invoice\Schema;
use App\Invoice\Account\PostalAddress;
use JMS\Serializer\Annotation\{Type, XmlAttribute, XmlNamespace, SerializedName, XmlRoot, XmlElement, XmlList, Exclude};


class Delivery
{
    #[SerializedName("ActualDeliveryDate")]
    #[XmlElement(cdata: false, namespace: Schema::CBC)]
    #[Type("DateTime<'Y-m-d'>")]
    private $actualDeliveryDate;

    #[SerializedName("DeliveryLocation")]
    #[XmlElement(cdata: false, namespace: Schema::CAC)]
    #[Type(PostalAddress::class)]
    private $deliveryLocation;

    #[SerializedName("DeliveryParty")]
    #[XmlElement(cdata: false, namespace: Schema::CBC)]
    #[Type("string")]
    private $deliveryParty;

    /**
     * get actual delivery party
     */
    public function getActualDeliveryDate(): ?\DateTime
    {
        return $this->actualDeliveryDate;
    }

    /**
     * Set actual delivery date
     */
    public function setActualDeliveryDate(\DateTime $actualDeliveryDate): Delivery
    {
        $this->actualDeliveryDate = $actualDeliveryDate;
        return $this;
    }

    /**
     * get Delivery Location
     */
    public function getDeliveryLocation(): ?PostalAddress
    {
        return $this->deliveryLocation;
    }

    /**
     * Set delivery location
     */
    public function setDeliveryLocation(?PostalAddress $deliveryLocation): Delivery
    {
        $this->deliveryLocation = $deliveryLocation;
        return $this;
    }

    /**
     * get Delivery Party
     */
    public function getDeliveryParty()
    {
        return $this->deliveryParty;
    }

    /**
     * set delivery party
     */
    public function setDeliveryParty($deliveryParty): Delivery
    {
        $this->deliveryParty = $deliveryParty;
        return $this;
    }
}
