<?php

namespace App\Invoice\Legal;

use Sabre\Xml\Writer;
use Sabre\Xml\XmlSerializable;
use App\Invoice\Schema;
use Sabre\Xml\Reader;
use Sabre\Xml\XmlDeserializable;
use JMS\Serializer\Annotation\{Type, XmlAttribute, XmlNamespace, SerializedName, XmlRoot, XmlElement, XmlList};

class LegalEntity implements XmlSerializable, XmlDeserializable
{
    #[SerializedName("RegistrationName")]
    #[XmlElement(cdata: false, namespace: Schema::CBC)]
    #[Type("string")]
    private $registrationName;

    #[SerializedName("CompanyID")]
    #[XmlElement(cdata: false, namespace: Schema::CBC)]
    #[Type("string")]
    private $companyId;

    #[SerializedName("CompanyIdAttributes")]
    #[XmlElement(cdata: false, namespace: Schema::CBC)]
    #[Type("string")]
    private $companyIdAttributes;

    #[SerializedName("CompanyLegalForm")]
    #[XmlElement(cdata: false, namespace: Schema::CBC)]
    #[Type("string")]
    private $companyLegalForm;

    /**
     * Seller name
     */
    public function getRegistrationNumber(): ?string
    {
        return $this->registrationName;
    }

    /**
     * Set seller name;
     */
    public function setRegistrationNumber(?string $registrationName): LegalEntity
    {
        $this->registrationName = $registrationName;
        return $this;
    }

    /**
     * Seller legal registration identifier
     */
    public function getCompanyId(): ?string
    {
        return $this->companyId;
    }

    /**
     * set Company ID
     */
    public function setCompanyId(?string $companyId, $attributes = null): LegalEntity
    {
        $this->companyId = $companyId;
        if (isset($attributes)) {
            $this->$companyIdAttributes = $attributes;
        }
        return $this;
    }

    /**
     * Company form legal
     */
    public function getCompanyLegalForm(): ?string
    {
        return $this->companyLegalForm;
    }

    /**
     * Set company form legal
     */
    public function setCompanyLegal(?string $companyLegalForm): LegalEntity
    {
        $this->companyLegalForm = $companyLegalForm;
        return $this;
    }

    /**
     * Serialize Legal Entity
     */
    public function xmlSerialize(Writer $writer): void
    {
        $writer->write([
            Schema::CBC . 'RegistrationName' => $this->registrationName
        ]);

        if ($this->companyId !== null) {
            $writer->write([
                'name' => Schema::CBC . 'CompanyID',
                'value' => $this->companyId,
                'attributes' => $this->companyIdAttributes
            ]);
        }
    }

    /**
     * Deserialize Legal Entity
     */
    static function xmlDeserialize(Reader $reader)
    {
        $legalEntity = new self();

        $keyValue = \Sabre\Xml\Element\KeyValue::xmlDeserialize($reader);

        if (isset($keyValue[Schema::CBC . 'RegistrationName'])) {
            $legalEntity->registrationName = $keyValue[Schema::CBC . 'RegistrationName'];
        }

        if (isset($keyValue[Schema::CBC . 'CompanyID'])) {
            $legalEntity->companyId = $keyValue[Schema::CBC . 'CompanyID'];
        }

        return $legalEntity;
    }
}
