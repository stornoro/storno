<?php

namespace App\Invoice\Financial;

use Sabre\Xml\Writer;
use Sabre\Xml\XmlSerializable;
use App\Invoice\Schema;
use Sabre\Xml\Reader;
use Sabre\Xml\XmlDeserializable;
use JMS\Serializer\Annotation\{Type, XmlAttribute, XmlNamespace, SerializedName, XmlRoot, XmlElement, XmlList};

class PayeeFinancialAccount implements XmlSerializable, XmlDeserializable
{
    #[SerializedName("ID")]
    #[XmlElement(cdata: false, namespace: Schema::CBC)]
    #[Type("string")]
    private $id;
    #[SerializedName("Name")]
    #[XmlElement(cdata: false, namespace: Schema::CBC)]
    #[Type("string")]
    private $name;
    #[SerializedName("FinancialInstitutionBranch")]
    #[XmlElement(cdata: false, namespace: Schema::CAC)]
    #[Type(FinancialInstitutionBranch::class)]
    private $financialInstitutionBranch;


    /**
     * @return string
     */
    public function getId(): ?string
    {
        return $this->id;
    }

    /**
     * @param string $id
     * @return PayeeFinancialAccount
     */
    public function setId(?string $id): PayeeFinancialAccount
    {
        $this->id = $id;
        return $this;
    }

    /**
     * @return string
     */
    public function getName(): ?string
    {
        return $this->name;
    }

    /**
     * @param string $name
     * @return PayeeFinancialAccount
     */
    public function setName(?string $name): PayeeFinancialAccount
    {
        $this->name = $name;
        return $this;
    }

    /**
     * @return FinancialInstitutionBranch
     */
    public function getFinancialInstitutionBranch(): ?FinancialInstitutionBranch
    {
        return $this->financialInstitutionBranch;
    }

    /**
     * @param FinancialInstitutionBranch $financialInstitutionBranch
     * @return PayeeFinancialAccount
     */
    public function setFinancialInstitutionBranch(?FinancialInstitutionBranch $financialInstitutionBranch): PayeeFinancialAccount
    {
        $this->financialInstitutionBranch = $financialInstitutionBranch;
        return $this;
    }

    public function xmlSerialize(Writer $writer): void
    {
        $writer->write([
            'name' => Schema::CBC . 'ID',
            'value' => $this->id,
            'attributes' => [
                //'schemeID' => 'IBAN'
            ]
        ]);

        if ($this->getName() !== null) {
            $writer->write([
                Schema::CBC . 'Name' => $this->getName()
            ]);
        }

        if ($this->getFinancialInstitutionBranch() !== null) {
            $writer->write([
                Schema::CAC . 'FinancialInstitutionBranch' => $this->getFinancialInstitutionBranch()
            ]);
        }
    }

    /**
     * Deserialize Payee Financial Account
     */
    static function xmlDeserialize(Reader $reader)
    {
        $payeeFinancial = new self();

        $keyValue =  Sabre\Xml\Element\KeyValue::xmlDeserialize($reader);

        if (isset($keyValue[Schema::CBC . 'ID'])) {
            $financial->id = $keyValue[Schema::CBC . 'ID'];
        }

        if (isset($keyValue[Schema::CBC . 'Name'])) {
            $financial->name = $keyValue[Schema::CBC . 'Name'];
        }

        if (isset($keyValue[Schema::CAC . 'FinancialInstitutionBranch'])) {
            $financial->financialInstitutionBranch = $keyValue[Schema::CAC . 'FinancialInstitutionBranch'];
        }

        return $payeeFinancial;
    }
}
