<?php

namespace App\Invoice\Legal;

use App\Invoice\Amount;
use Sabre\Xml\Writer;
use Sabre\Xml\XmlSerializable;
use App\Invoice\Schema;
use App\Invoice\Invoice\GenerateInvoice;
use Sabre\Xml\Reader;
use Sabre\Xml\XmlDeserializable;
use JMS\Serializer\Annotation\{Type, XmlAttribute, XmlNamespace, SerializedName, XmlRoot, XmlElement, XmlList};

class LegalMonetaryTotal implements XmlSerializable, XmlDeserializable
{
    #[SerializedName("LineExtensionAmount")]
    #[XmlElement(cdata: false, namespace: Schema::CBC)]
    #[Type(Amount::class)]
    private $lineExtensionAmount;

    #[SerializedName("TaxExclusiveAmount")]
    #[XmlElement(cdata: false, namespace: Schema::CBC)]
    #[Type(Amount::class)]
    private $taxExclusiveAmount;

    #[SerializedName("TaxInclusiveAmount")]
    #[XmlElement(cdata: false, namespace: Schema::CBC)]
    #[Type(Amount::class)]
    private $taxInclusiveAmount;

    #[SerializedName("AllowanceTotalAmount")]
    #[XmlElement(cdata: false, namespace: Schema::CBC)]
    #[Type(Amount::class)]
    private Amount $allowanceTotalAmount;


    #[SerializedName("ChargeTotalAmount")]
    #[XmlElement(cdata: false, namespace: Schema::CBC)]
    #[Type(Amount::class)]
    private Amount $chargeTotalAmount;

    #[SerializedName("PayableAmount")]
    #[XmlElement(cdata: false, namespace: Schema::CBC)]
    #[Type(Amount::class)]
    private Amount $payableAmount;

    /**
     * @return Amount
     */
    public function getLineExtensionAmount(): Amount
    {
        return $this->lineExtensionAmount;
    }

    /**
     * @param Amount $lineExtensionAmount
     * @return LegalMonetaryTotal
     */
    public function setLineExtensionAmount(Amount $lineExtensionAmount): LegalMonetaryTotal
    {
        $this->lineExtensionAmount = $lineExtensionAmount;
        return $this;
    }

    /**
     * @return Amount
     */
    public function getTaxExclusiveAmount(): ?Amount
    {
        return $this->taxExclusiveAmount;
    }

    /**
     * @param Amount $taxExclusiveAmount
     * @return LegalMonetaryTotal
     */
    public function setTaxExclusiveAmount(?Amount $taxExclusiveAmount): LegalMonetaryTotal
    {
        $this->taxExclusiveAmount = $taxExclusiveAmount;
        return $this;
    }

    /**
     * @return Amount
     */
    public function getTaxInclusiveAmount(): ?Amount
    {
        return $this->taxInclusiveAmount;
    }

    /**
     * @param Amount $taxInclusiveAmount
     * @return LegalMonetaryTotal
     */
    public function setTaxInclusiveAmount(Amount $taxInclusiveAmount): LegalMonetaryTotal
    {
        $this->taxInclusiveAmount = $taxInclusiveAmount;
        return $this;
    }

    /**
     * @return Amount
     */
    public function getAllowanceTotalAmount(): ?Amount
    {
        return $this->allowanceTotalAmount;
    }

    /**
     * @param Amount $allowanceTotalAmount
     * @return LegalMonetaryTotal
     */
    public function setAllowanceTotalAmount(?Amount $allowanceTotalAmount): LegalMonetaryTotal
    {
        $this->allowanceTotalAmount = $allowanceTotalAmount;
        return $this;
    }

    /**
     * @return Amount
     */
    public function getPayableAmount(): ?Amount
    {
        return $this->payableAmount;
    }

    /**
     * @param Amount $payableAmount
     * @return LegalMonetaryTotal
     */
    public function setPayableAmount(?Amount $payableAmount): LegalMonetaryTotal
    {
        $this->payableAmount = $payableAmount;
        return $this;
    }


    /**
     * Get the value of chargeTotalAmount
     */
    public function getChargeTotalAmount(): Amount
    {
        return $this->chargeTotalAmount;
    }

    /**
     * Set the value of chargeTotalAmount
     */
    public function setChargeTotalAmount($chargeTotalAmount): self
    {
        $this->chargeTotalAmount = $chargeTotalAmount;

        return $this;
    }

    /**
     * The xmlSerialize method is called during xml writing.
     *
     * @param Writer $writer
     * @return void
     */
    public function xmlSerialize(Writer $writer): void
    {
        $writer->write([
            [
                'name' => Schema::CBC . 'LineExtensionAmount',
                'value' => number_format($this->lineExtensionAmount, 2, '.', ''),
                'attributes' => [
                    'currencyID' => GenerateInvoice::$currencyID
                ]

            ],
            [
                'name' => Schema::CBC . 'TaxExclusiveAmount',
                'value' => number_format($this->taxExclusiveAmount, 2, '.', ''),
                'attributes' => [
                    'currencyID' => GenerateInvoice::$currencyID
                ]

            ],
            [
                'name' => Schema::CBC . 'TaxInclusiveAmount',
                'value' => number_format($this->taxInclusiveAmount, 2, '.', ''),
                'attributes' => [
                    'currencyID' => GenerateInvoice::$currencyID
                ]

            ],
            [
                'name' => Schema::CBC . 'AllowanceTotalAmount',
                'value' => number_format($this->allowanceTotalAmount, 2, '.', ''),
                'attributes' => [
                    'currencyID' => GenerateInvoice::$currencyID
                ]

            ],
            [
                'name' => Schema::CBC . 'PayableAmount',
                'value' => number_format($this->payableAmount, 2, '.', ''),
                'attributes' => [
                    'currencyID' => GenerateInvoice::$currencyID
                ]
            ],
        ]);
    }

    /**
     * Deserialize LegalMonetaryTotal
     */
    static function xmlDeserialize(Reader $reader)
    {
        $legalMonetaryTotal = new self();

        $keyValue = \Sabre\Xml\Element\KeyValue::xmlDeserialize($reader);

        if (isset($keyValue[Schema::CBC . 'LineExtensionAmount'])) {
            $legalMonetaryTotal->lineExtensionAmount = $keyValue[Schema::CBC . 'LineExtensionAmount'];
        }

        if (isset($keyValue[Schema::CBC . 'TaxExclusiveAmount'])) {
            $legalMonetaryTotal->taxExclusiveAmount = $keyValue[Schema::CBC . 'TaxExclusiveAmount'];
        }

        if (isset($keyValue[Schema::CBC . 'TaxInclusiveAmount'])) {
            $legalMonetaryTotal->taxInclusiveAmount = $keyValue[Schema::CBC . 'TaxInclusiveAmount'];
        }

        if (isset($keyValue[Schema::CBC . 'AllowanceTotalAmount'])) {
            $legalMonetaryTotal->allowanceTotalAmount = $keyValue[Schema::CBC . 'AllowanceTotalAmount'];
        }

        if (isset($keyValue[Schema::CBC . 'PayableAmount'])) {
            $legalMonetaryTotal->payableAmount = $keyValue[Schema::CBC . 'PayableAmount'];
        }

        return $legalMonetaryTotal;
    }
}
