<?php

namespace App\Invoice;

use Sabre\Xml\Writer;
use Sabre\Xml\XmlSerializable;
use App\Invoice\Tax\TaxCategory;
use App\Invoice\Schema;
use Sabre\Xml\Reader;
use Sabre\Xml\XmlDeserializable;
use JMS\Serializer\Annotation\{Type, XmlAttribute, XmlNamespace, SerializedName, XmlRoot, XmlElement, XmlList};

class AllowanceCharge implements XmlSerializable, XmlDeserializable
{
    #[SerializedName("ChargeIndicator")]
    #[XmlElement(cdata: false, namespace: Schema::CBC)]
    #[Type("string")]
    private $chargeIndicator;

    #[SerializedName("AllowanceChargeReasonCode")]
    #[XmlElement(cdata: false, namespace: Schema::CBC)]
    #[Type("string")]
    private $allowanceChargeReasonCode;

    #[SerializedName("AllowanceChargeReason")]
    #[XmlElement(cdata: false, namespace: Schema::CBC)]
    #[Type("string")]
    private $allowanceChargeReason;
    private $multiplierFactorNumeric;
    private $baseAmount;

    #[SerializedName("Amount")]
    #[XmlElement(cdata: false, namespace: Schema::CBC)]
    #[Type(Amount::class)]
    private $amount;

    #[SerializedName("TaxCategory")]
    #[XmlElement(cdata: false, namespace: Schema::CAC)]
    #[Type(TaxCategory::class)]
    private $taxCategory;


    /**
     * Use “true” when informing about Charges and “false” when informing about Allowances.
     */
    public function isChargeIndicator(): ?bool
    {
        return $this->chargeIndicator;
    }

    /**
     * set charge indicator
     */
    public function setChargeIndicator(bool $chargeIndicator): AllowanceCharge
    {
        $this->chargeIndicator = $chargeIndicator;
        return $this;
    }

    /**
     * Document level allowance or charge reason code
     */
    public function getAllowanceChargeReasonCode(): ?int
    {
        return $this->allowanceChargeReasonCode;
    }

    /**
     * set document level reason code
     */
    public function setAllowanceReasonCode(int $allowanceChargeReasonCode): AllowanceCharge
    {
        $this->allowanceChargeReasonCode = $allowanceChargeReasonCode;
        return $this;
    }

    /**
     *  Document level allowance or charge reason
     */
    public function getAllowanceChargeReason(): ?string
    {
        return $this->allowanceChargeReason;
    }

    /**
     * set document level reason code
     */
    public function setAllowanceReason(string $allowanceChargeReason): AllowanceCharge
    {
        $this->allowanceChargeReason = $allowanceChargeReason;
        return $this;
    }

    /**
     * @return int
     */
    public function getMultiplierFactorNumeric(): ?int
    {
        return $this->multiplierFactorNumeric;
    }

    /**
     * @param int $multiplierFactorNumeric
     * @return AllowanceCharge
     */
    public function setMultiplierFactorNumeric(?int $multiplierFactorNumeric): AllowanceCharge
    {
        $this->multiplierFactorNumeric = $multiplierFactorNumeric;
        return $this;
    }

    /**
     * get base amount
     */
    public function getBaseAmount(): ?float
    {
        return $this->baseAmount;
    }

    /**
     * set base amount
     */
    public function setBaseAmount(float $baseAmount): AllowanceCharge
    {
        $this->baseAmount = $baseAmount;
        return $this;
    }

    /**
     * get amount
     */
    public function getAmount(): ?Amount
    {
        return $this->amount;
    }

    /**
     * set base amount
     */
    public function setAmount(Amount $amount): AllowanceCharge
    {
        $this->amount = $amount;
        return $this;
    }

    /**
     * return Tax Category
     */
    public function getTaxCategory(): ?TaxCategory
    {
        return $this->taxCategory;
    }

    /**
     * Set tax category
     */
    public function setTaxCategory(?TaxCategory $taxCategory): AllowanceCharge
    {
        $this->taxCategory = $taxCategory;
        return $this;
    }

    /**
     * Serialize Allowance Charge
     */
    public function xmlSerialize(Writer $writer): void
    {
        $writer->write([
            Schema::CBC . 'ChargeIndicator' => $this->chargeIndicator
        ]);

        if ($this->allowanceChargeReasonCode !== null) {
            $writer->write([
                Schema::CBC . 'AllowanceChargeReasonCode' => $this->allowanceChargeReasonCode
            ]);
        }

        if ($this->allowanceChargeReason !== null) {
            $writer->write([
                Schema::CBC . 'AllowanceChargeReason' => $this->allowanceChargeReason
            ]);
        }

        if ($this->multiplierFactorNumeric !== null) {
            $writer->write([
                Schema::CBC . 'MultiplierFactorNumeric' => $this->multiplierFactorNumeric
            ]);
        }

        $writer->write([
            'name' => Schema::CBC . 'Amount',
            'value' => number_format($this->amount, 2, '.', ''),
            'attributes' => [
                'currencyID' => GenerateInvoice::$currencyID
            ]
        ]);

        if ($this->taxCategory !== null) {
            $writer->write([
                Schema::CAC . 'TaxCategory' => $this->taxCategory
            ]);
        }

        if ($this->baseAmount !== null) {
            $writer->write([
                'name' => Schema::CBC . 'BaseAmount',
                'value' => $this->baseAmount,
                'attributes' => [
                    'currencyID' => GenerateInvoice::$currencyID
                ]
            ]);
        }
    }

    /**
     * Deserialize AllowanceCharge
     */
    static function xmlDeserialize(Reader $reader)
    {
        $allowanceCharge = new self();
        $keyValue =  Sabre\Xml\Element\KeyValue::xmlDeserialize($reader);
        if (isset($keyValue[Schema::CBC . 'ChargeIndicator'])) {
            $allowanceCharge->chargeIndicator = $keyValue[Schema::CBC . 'ChargeIndicator'];
        }

        if (isset($keyValue[Schema::CBC . 'AllowanceChargeReasonCode'])) {
            $allowanceCharge->allowanceChargeReasonCode = $keyValue[Schema::CBC . 'AllowanceChargeReasonCode'];
        }

        if (isset($keyValue[Schema::CBC . 'AllowanceChargeReason'])) {
            $allowanceCharge->allowanceChargeReason = $keyValue[Schema::CBC . 'AllowanceChargeReason'];
        }

        if (isset($keyValue[Schema::CBC . 'MultiplierFactorNumeric'])) {
            $allowanceCharge->multiplierFactorNumeric = $keyValue[Schema::CBC . 'MultiplierFactorNumeric'];
        }

        if (isset($keyValue[Schema::CBC . 'Amount'])) {
            $allowanceCharge->amount = $keyValue[Schema::CBC . 'Amount'];
        }

        if (isset($keyValue[Schema::CAC . 'TaxCategory'])) {
            $allowanceCharge->taxCategory = $keyValue[Schema::CAC . 'TaxCategory'];
        }

        if (isset($keyValue[Schema::CBC . 'BaseAmount'])) {
            $allowanceCharge->baseAmount = $keyValue[Schema::CBC . 'BaseAmount'];
        }

        return $allowanceCharge;
    }
}
