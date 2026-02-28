<?php

namespace App\Invoice\Party;

use App\Invoice\Schema;
use JMS\Serializer\Annotation\{Type, XmlAttribute, XmlNamespace, SerializedName, XmlRoot, XmlElement, XmlList};

class PartyIdentification
{

    #[SerializedName("ID")]
    #[XmlElement(cdata: false, namespace: Schema::CBC)]
    #[Type("string")]
    private $id;

    public function __construct($id = null)
    {
        $this->id = $id;

        return $this;
    }

    public function setId($id)
    {
        $this->id = $id;
        return $this;
    }

    public function getId()
    {
        return $this->id;
    }
}
