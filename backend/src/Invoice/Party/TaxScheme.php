<?php

namespace App\Invoice\Party;

use Sabre\Xml\Writer;
use Sabre\Xml\XmlSerializable;
use App\Invoice\Schema;
use Sabre\Xml\Reader;
use Sabre\Xml\XmlDeserializable;
use JMS\Serializer\Annotation\{Type, XmlAttribute, XmlNamespace, SerializedName, XmlRoot, XmlElement, XmlList};

class TaxScheme implements XmlSerializable, XmlDeserializable
{

    #[SerializedName("ID")]
    #[XmlElement(cdata: false, namespace: Schema::CBC)]
    #[Type("string")]
    private $id;
    /**
     * For Seller Vat Identifier get
     * Example value: VAT
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     *Set ID
     */
    public function setId(?string $id): TaxScheme
    {
        $this->id = $id;
        return $this;
    }

    /**
     * Serialize XML Tax Scheme
     */
    public function xmlSerialize(Writer $writer): void
    {
        $writer->write([
            Schema::CBC . 'ID' => $this->id
        ]);
    }

    /**
     * Deserialize XML TaxScheme
     */
    static function xmlDeserialize(Reader $reader)
    {
        $taxScheme = new self();

        $keyValue = \Sabre\Xml\Element\KeyValue::xmlDeserialize($reader);

        if (isset($keyValue[Schema::CBC . 'ID'])) {
            $taxScheme->id = $keyValue[Schema::CBC . 'ID'];
        }
        return $taxScheme;
    }
}
