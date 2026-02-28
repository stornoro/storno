<?php

namespace App\Invoice\Tax;

use App\Invoice\Amount;
use InvalidArgumentException as InvalidArgumentException;

use Sabre\Xml\Writer;
use Sabre\Xml\XmlSerializable;
use App\Invoice\Schema;
use App\Invoice\Invoice\GenerateInvoice;
use Sabre\Xml\Reader;
use Sabre\Xml\XmlDeserializable;
use JMS\Serializer\Annotation\{Type, XmlAttribute, XmlNamespace, SerializedName, XmlRoot, XmlElement, XmlList};

class TaxTotal implements XmlSerializable, XmlDeserializable
{
    #[SerializedName("TaxAmount")]
    #[XmlElement(cdata: false, namespace: Schema::CBC)]
    #[Type(Amount::class)]
    private Amount $taxAmount;

    #[SerializedName("TaxSubtotal")]
    #[XmlList(inline: true, entry: "TaxSubtotal", namespace: Schema::CAC)]
    #[Type("array<App\Invoice\Tax\TaxSubTotal>")]
    private $taxSubTotals = [];

    private $currency = null;

    /**
     * Invoice total VAT amount, Invoice total VAT amount in accounting currency
     */
    public function getTaxAmount(): Amount
    {
        return $this->taxAmount;
    }

    /**
     * Set tax amount
     */
    public function setTaxAmount(Amount $taxAmount): TaxTotal
    {
        $this->taxAmount = $taxAmount;
        return $this;
    }

    /**
     *  VAT BREAKDOWN
     *  @return TaxSubTotal[]
     */
    public function getTaxSubtotal(): array
    {
        return $this->taxSubTotals;
    }

    /**
     * Set tax subtotal
     */
    public function setTaxSubtotal(TaxSubTotal $taxSubTotals): TaxTotal
    {
        $this->taxSubTotals[] = $taxSubTotals;
        return $this;
    }

    /**
     * validation for tax amount
     */
    public function validate()
    {
        if ($this->taxAmount === null) {
            throw new InvalidArgumentException('Missing taxtotal tax amount');
        }
    }

    /**
     * Serialize TaxtTotal
     */
    public function xmlSerialize(Writer $writer): void
    {
        $writer->write([
            'name' => Schema::CBC . 'TaxAmount',
            'value' => number_format($this->getTaxAmount()->getValue(), 2, '.', ''),
            'attributes' => [
                'currencyID' => $this->currency ?? GenerateInvoice::$currencyID
            ]
        ]);

        foreach ($this->taxSubTotals as $taxSubTotal) {
            $writer->write([Schema::CAC . 'TaxSubtotal' => $taxSubTotal]);
        }
    }

    /**
     * Deserilize TaxTotal
     */
    static function xmlDeserialize(Reader $reader)
    {
        $taxTotal = new self();

        $keyValue = \Sabre\Xml\Element\KeyValue::xmlDeserialize($reader);

        if (isset($keyValue[Schema::CBC . 'TaxAmount'])) {
            $taxTotal->taxAmount = $keyValue[Schema::CBC . 'TaxAmount'];
        }

        if (isset($keyValue[Schema::CAC . 'TaxSubtotal'])) {
            $taxTotal->taxSubTotals = $keyValue[Schema::CAC . 'TaxSubtotal'];
        }
        return $taxTotal;
    }
}
