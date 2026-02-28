<?php

namespace App\Invoice\Party;


use App\Invoice\Legal\LegalEntity;
use App\Invoice\Account\PostalAddress;
use App\Invoice\Account\Contact;
use App\Invoice\Party\PartyTaxScheme;
use App\Invoice\Schema;
use JMS\Serializer\Annotation\{Type, XmlAttribute, XmlNamespace, SerializedName, XmlRoot, XmlElement, XmlList};


class Party
{

    #[SerializedName("PartyName")]
    #[XmlElement(cdata: false, namespace: Schema::CAC)]
    #[Type(PartyName::class)]
    private $name;

    #[SerializedName("PartyIdentification")]
    #[XmlElement(cdata: false, namespace: Schema::CAC)]
    #[Type(PartyIdentification::class)]
    private $partyIdentificationId;

    #[SerializedName("PostalAddress")]
    #[XmlElement(cdata: false, namespace: Schema::CAC)]
    #[Type(PostalAddress::class)]
    private  $postalAddress;

    #[SerializedName("PhysicalLocation")]
    #[XmlElement(cdata: false, namespace: Schema::CAC)]
    #[Type(PostalAddress::class)]
    private $physicalLocation;


    #[SerializedName("PartyTaxScheme")]
    #[XmlElement(cdata: false, namespace: Schema::CAC)]
    #[Type(PartyTaxScheme::class)]
    private $partyTaxScheme;

    #[SerializedName("PartyLegalEntity")]
    #[XmlElement(cdata: false, namespace: Schema::CAC)]
    #[Type(LegalEntity::class)]
    private $legalEntity;


    #[SerializedName("Contact")]
    #[XmlElement(cdata: false, namespace: Schema::CAC)]
    #[Type(Contact::class)]
    private $contact;


    /**
     * @return PartyName
     */
    public function getName(): ?PartyName
    {
        return $this->name;
    }

    /**
     * @param PartyName $name
     * @return Party
     */
    public function setName($name): self
    {
        $this->name = $name;
        return $this;
    }

    /**
     * @return PartyIdentification
     */
    public function getPartyIdentificationId(): ?PartyIdentification
    {
        return $this->partyIdentificationId;
    }

    /**
     * @param PartyIdentification $partyIdentificationId
     * @return Party
     */
    public function setPartyIdentificationId($partyIdentificationId): self
    {
        $this->partyIdentificationId = $partyIdentificationId;
        return $this;
    }


    /**
     * @return PostalAddress
     */
    public function getPostalAddress(): ?PostalAddress
    {
        return $this->postalAddress;
    }

    /**
     * @param PostalAddress $postalAddress
     * @return Party
     */
    public function setPostalAddress(?PostalAddress $postalAddress): self
    {
        $this->postalAddress = $postalAddress;
        return $this;
    }

    /**
     * @return LegalEntity
     */
    public function getLegalEntity(): ?LegalEntity
    {
        return $this->legalEntity;
    }

    /**
     * @param LegalEntity $legalEntity
     * @return Party
     */
    public function setLegalEntity(?LegalEntity $legalEntity): self
    {
        $this->legalEntity = $legalEntity;
        return $this;
    }

    /**
     * @return PostalAddress
     */
    public function getPhysicalLocation(): ?PostalAddress
    {
        return $this->physicalLocation;
    }

    /**
     * @param PostalAddress $physicalLocation
     * @return Party
     */
    public function setPhysicalLocation(?PostalAddress $physicalLocation): self
    {
        $this->physicalLocation = $physicalLocation;
        return $this;
    }

    /**
     * @return PartyTaxScheme
     */
    public function getPartyTaxScheme(): ?PartyTaxScheme
    {
        return $this->partyTaxScheme;
    }

    /**
     * @param PartyTaxScheme $partyTaxScheme
     * @return Party
     */
    public function setPartyTaxScheme(PartyTaxScheme $partyTaxScheme)
    {
        $this->partyTaxScheme = $partyTaxScheme;
        return $this;
    }

    /**
     * @return Contact
     */
    public function getContact(): ?Contact
    {
        return $this->contact;
    }

    /**
     * @param Contact $contact
     * @return Party
     */
    public function setContact(?Contact $contact): self
    {
        $this->contact = $contact;
        return $this;
    }
}
