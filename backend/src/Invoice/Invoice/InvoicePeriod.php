<?php

namespace App\Invoice\Invoice;

use InvalidArgumentException as InvalidArgumentException;
use DateTime as DateTime;
use Sabre\Xml\Writer;
use Sabre\Xml\XmlSerializable;
use App\Invoice\Schema;
use Sabre\Xml\Reader;
use Sabre\Xml\XmlDeserializable;
use JMS\Serializer\Annotation\{Type, XmlAttribute, XmlNamespace, SerializedName, XmlRoot, XmlElement, XmlList, Exclude};

class InvoicePeriod
{
    #[SerializedName("StartDate")]
    #[XmlElement(cdata: false, namespace: Schema::CBC)]
    #[Type("DateTime<'Y-m-d'>")]
    private $startDate;

    #[SerializedName("EndDate")]
    #[XmlElement(cdata: false, namespace: Schema::CBC)]
    #[Type("DateTime<'Y-m-d'>")]
    private $endDate;

    /**
     *  Invoice line period start date
     */
    public function getStartDate(): ?Datetime
    {
        return $this->startDate;
    }

    /**
     * Set start Date
     */
    public function setStartDate(?Datetime $startDate): InvoicePeriod
    {
        $this->startDate = $startDate;
        return $this;
    }

    /**
     *  Invoice line period end date
     */
    public function getEndDate(): ?Datetime
    {
        return $this->endDate;
    }

    /**
     * Set start Date
     */
    public function setEndDate(?Datetime $endDate): InvoicePeriod
    {
        $this->endDate = $endDate;
        return $this;
    }

    /**
     * validation date
     */
    public function validate()
    {
        if ($this->startDate === null && $this->endDate === null) {
            throw new InvalidArgumentException('Missing start date and end date');
        }
    }
}
