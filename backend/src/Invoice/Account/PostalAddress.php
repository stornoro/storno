<?php

namespace App\Invoice\Account;

use Sabre\Xml\Writer;
use Sabre\Xml\Reader;
use Sabre\Xml\XmlSerializable;
use Sabre\Xml\XmlDeserializable;
use App\Invoice\Schema;
use App\Invoice\Account\Country;
use JMS\Serializer\Annotation\{Type, XmlAttribute, XmlNamespace, SerializedName, XmlRoot, XmlElement, XmlList};


class PostalAddress
{

    #[SerializedName("StreetName")]
    #[XmlElement(cdata: false, namespace: Schema::CBC)]
    #[Type("string")]
    private $streetName;

    #[SerializedName("AdditionalStreetName")]
    #[XmlElement(cdata: false, namespace: Schema::CBC)]
    #[Type("string")]
    private $additionalStreetName;

    #[SerializedName("CityName")]
    #[XmlElement(cdata: false, namespace: Schema::CBC)]
    #[Type("string")]
    private $cityName;

    #[SerializedName("PostalZone")]
    #[XmlElement(cdata: false, namespace: Schema::CBC)]
    #[Type("string")]
    private $postalZone;

    #[SerializedName("CountrySubentity")]
    #[XmlElement(cdata: false, namespace: Schema::CBC)]
    #[Type("string")]
    private $countrySubentity;

    #[SerializedName("Country")]
    #[XmlElement(cdata: false, namespace: Schema::CAC)]
    #[Type(Country::class)]
    private $country;

    #[SerializedName("BuildingNumber")]
    #[XmlElement(cdata: false, namespace: Schema::CBC)]
    #[Type("string")]
    private $buildingNumber;


    /**
     * get Building Name
     */
    public function getBuildingNumber(): ?string
    {
        return $this->buildingNumber;
    }

    /**
     * Set Building Name
     */
    public function setBuildingNumber(?string $buildingNumber): PostalAddress
    {
        $this->buildingNumber = $buildingNumber;
        return $this;
    }

    /**
     * Get street name
     */
    public function getStreetName(): ?string
    {
        return $this->streetName;
    }

    /**
     * Set street Name
     */
    public function setStreetName(?string $streetName): PostalAddress
    {
        $this->streetName = $streetName;
        return $this;
    }

    /**
     * Get Additional Street Name
     */
    public function getAdditonalStreetName(): ?string
    {
        return $this->additionalStreetName;
    }

    /**
     * Set addional street name
     */
    public function setAdditionalStreetName(?string $additionalStreetName): PostalAddress
    {
        $this->additionalStreetName = $additionalStreetName;
        return $this;
    }

    /**
     * get city name
     */
    public function getCityName(): ?string
    {
        return $this->cityName;
    }

    /**
     * Set City Name
     */
    public function setCityName(?string $cityName): PostalAddress
    {
        $this->cityName = $cityName;
        return $this;
    }

    /**
     * Get postal zone
     */
    public function getPostalZone(): ?string
    {
        return $this->postalZone;
    }

    /**
     * Set postal zone
     */
    public function setPostalZone(?string $postalZone): PostalAddress
    {
        $this->postalZone = $postalZone;
        return $this;
    }

    /**
     * @return Country
     */
    public function getCountry(): ?Country
    {
        return $this->country;
    }

    /**
     * Set Country
     */
    public function setCountry(Country $country): PostalAddress
    {
        $this->country = $country;
        return $this;
    }


    /**
     * Get the value of countrySubentity
     */
    public function getCountrySubentity(): string
    {
        return $this->countrySubentity;
    }

    /**
     * Set the value of countrySubentity
     */
    public function setCountrySubentity($countrySubentity): PostalAddress
    {
        $this->countrySubentity = $countrySubentity;

        return $this;
    }
}
