<?php

namespace App\Invoice\Party;

use App\Invoice\Schema;
use JMS\Serializer\Annotation\{Type, XmlAttribute, XmlNamespace, SerializedName, XmlRoot, XmlElement, XmlList};

class PartyName
{

    #[SerializedName("Name")]
    #[XmlElement(cdata: false, namespace: Schema::CBC)]
    #[Type("string")]
    private $name;

    public function __construct($name = null)
    {
        $this->name = $name;
        return $this;
    }

    public function setName($name)
    {
        $this->name = $name;
        return $this;
    }

    public function getName()
    {
        return $this->name;
    }
}
