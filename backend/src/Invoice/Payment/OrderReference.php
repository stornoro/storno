<?php

namespace App\Invoice\Payment;

use App\Invoice\Schema;
use JMS\Serializer\Annotation\{Type, XmlAttribute, XmlNamespace, SerializedName, XmlRoot, XmlElement, XmlList, Exclude};


class OrderReference
{

    #[SerializedName("ID")]
    #[XmlElement(cdata: false, namespace: Schema::CBC)]
    #[Type("string")]
    private $id;

    #[SerializedName("SalesOrderID")]
    #[XmlElement(cdata: false, namespace: Schema::CBC)]
    #[Type("string")]
    private $salesOrderId;

    /**
     * Purchase order reference
     * Example value: 98776
     */
    public function getId(): ?string
    {
        return $this->id;
    }

    /**
     * set order id
     */
    public function setId(?string $id): OrderReference
    {
        $this->id = $id;
        return $this;
    }

    /**
     *  Sales order reference
     *  Example value: 112233
     */
    public function getSalesOrderId(): ?string
    {
        return $this->salesOrderId;
    }

    /**
     * Set sales order id
     */
    public function setSalesOrderId(?string $salesOrderId): OrderReference
    {
        $this->salesOrderId = $salesOrderId;
        return $this;
    }
}
