<?php

namespace App\Invoice;

use App\Invoice\Party\Party;
use App\Invoice\Schema;
use JMS\Serializer\Annotation\{Type, XmlAttribute, XmlNamespace, SerializedName, XmlRoot, XmlElement, XmlList};

class AccountParty
{

    #[SerializedName("Party")]
    #[XmlElement(cdata: false, namespace: Schema::CAC)]
    #[Type(Party::class)]
    private $party;

    public function __construct(Party $party = null)
    {
        $this->party = $party;
        return $this;
    }

    public function setParty(Party $party)
    {
        $this->party = $party;
        return $this;
    }

    public function getParty(): Party
    {
        return $this->party;
    }
}
