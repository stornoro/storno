<?php

namespace App\Invoice\Account;


use App\Invoice\Schema;
use JMS\Serializer\Annotation\{Type, XmlAttribute, XmlNamespace, SerializedName, XmlRoot, XmlElement, XmlList};


class Contact
{

    #[SerializedName("Name")]
    #[XmlElement(cdata: false, namespace: Schema::CBC)]
    #[Type("string")]
    private $name;

    #[SerializedName("Telephone")]
    #[XmlElement(cdata: false, namespace: Schema::CBC)]
    #[Type("string")]
    private $telephone;

    #[SerializedName("Telefax")]
    #[XmlElement(cdata: false, namespace: Schema::CBC)]
    #[Type("string")]
    private $telefax;

    #[SerializedName("ElectronicMail")]
    #[XmlElement(cdata: false, namespace: Schema::CBC)]
    #[Type("string")]
    private $electronicMail;


    /**
     * Get Name
     */
    public function getName(): ?string
    {
        return $this->name;
    }

    /**
     * Set Name
     */
    public function setName(?string $name): Contact
    {
        $this->name = $name;
        return $this;
    }

    /**
     * Get telephone
     */
    public function getTelephone(): ?string
    {
        return $this->telephone;
    }


    /**
     * get telefax
     */
    public function getTelefax(): ?string
    {
        return $this->telefax;
    }

    /**
     * set telefax
     */
    public function setTelefax(?string $telefax): Contact
    {
        $this->telefax = $telefax;
        return $this;
    }

    /**
     * Set telephone
     */
    public function setTelephone(?string $telephone): Contact
    {
        $this->telephone = $telephone;
        return $this;
    }

    /**
     * get electroic Mail
     */
    public function getElectronicMail(): ?string
    {
        return $this->electronicMail;
    }

    /**
     * Set electronic mail
     */
    public function setElectronicMail(?string $electronicMail): Contact
    {
        $this->electronicMail = $electronicMail;
        return $this;
    }
}
