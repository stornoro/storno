<?php

namespace App\Invoice\Invoice;

use App\Invoice\Payment\UnitCode;
use App\Invoice\Schema;
use JMS\Serializer\Annotation\{Type, XmlAttribute, XmlNamespace, SerializedName, XmlValue, XmlElement, XmlList};

class Quantity
{

    #[SerializedName("unitCode")]
    #[XmlAttribute]
    #[Type("string")]
    private $unitCode = UnitCode::UNIT;
    // Recommendation 20, including Recommendation 21 codes - prefixed with X (UN/ECE)
    // https://docs.peppol.eu/poacc/billing/3.0/codelist/UNECERec20/


    #[XmlValue(cdata: false)]
    #[Type("float")]
    private $value;

    public function __construct($unitCode = null, $value = null)
    {
        $this->unitCode = $unitCode;
        $this->value = $value;
        return $this;
    }

    public function setUnitCode($unitCode)
    {
        $this->unitCode = $unitCode;
        return $this;
    }

    public function getUnitCode()
    {
        return $this->unitCode;
    }

    public function setValue($value)
    {
        $this->value = $value;
        return $this;
    }

    public function getValue()
    {
        return $this->value;
    }
}
