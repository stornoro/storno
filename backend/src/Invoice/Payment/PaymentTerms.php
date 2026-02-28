<?php

namespace App\Invoice\Payment;

use Sabre\Xml\Writer;
use Sabre\Xml\XmlSerializable;
use App\Invoice\Schema;
use Sabre\Xml\Reader;
use Sabre\Xml\XmlDeserializable;
use JMS\Serializer\Annotation\{Type, XmlAttribute, XmlNamespace, SerializedName, XmlRoot, XmlElement, XmlList};

class PaymentTerms implements XmlSerializable, XmlDeserializable
{
    #[SerializedName("Note")]
    #[XmlElement(cdata: false, namespace: Schema::CBC)]
    #[Type("string")]
    private $note;

    /**
     *  Payment terms
     */
    public function getNote(): ?string
    {
        return $this->note;
    }

    /**
     * set note
     */
    public function setNote(?string $note): PaymentTerms
    {
        $this->note = $note;
        return $this;
    }
    /**
     * Serialize Payment Terms
     */
    public function xmlSerialize(Writer $writer): void
    {
        if ($this->note !== null) {
            $writer->write([Schema::CBC . 'Note' => $this->note]);
        }
    }

    /**
     * Deserialize Payment Terms
     */
    static function xmlDeserialize(Reader $reader)
    {
        $paymentTerms = new self();

        $keyValue = \Sabre\Xml\Element\KeyValue::xmlDeserialize($reader);
        if (isset($keyValue[Schema::CBC . 'Note'])) {
            $paymentTerms->note = $keyValue[Schema::CBC . 'Note'];
        }
        return $paymentTerms;
    }
}
