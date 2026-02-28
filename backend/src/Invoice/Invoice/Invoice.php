<?php

namespace App\Invoice\Invoice;

use Sabre\Xml\Writer;
use Sabre\Xml\XmlSerializable;
use App\Invoice\Account\Delivery;
use App\Invoice\AccountParty;
use App\Invoice\AllowanceCharge;
use App\Invoice\Documents\AdditionalDocumentReference;
use App\Invoice\Documents\ContractDocumentReference;
use App\Invoice\Party\Party;
use App\Invoice\Invoice\InvoiceLine;
use App\Invoice\Legal\LegalMonetaryTotal;
use App\Invoice\Payment\PaymentTerms;
use App\Invoice\Invoice\InvoicePeriod;
use App\Invoice\Payment\PaymentMeans;
use App\Invoice\Payment\OrderReference;
use App\Invoice\Tax\TaxTotal;
use App\Invoice\Schema;

use DateTime as DateTime;
use InvalidArgumentException as InvalidArgumentException;
use App\Invoice\Invoice\InvoiceTypeCode;
use JMS\Serializer\Annotation\{Type, XmlAttribute, XmlNamespace, SerializedName, XmlRoot, XmlElement, XmlList, Exclude};

#[XmlNamespace(uri: Schema::UBL)]
#[XmlNamespace(uri: Schema::CBC, prefix: "cbc")]
#[XmlNamespace(uri: Schema::CAC, prefix: "cac")]
#[XmlRoot("Invoice")]
class Invoice
{
    /**
     * Minimum invoice requires:
     * cbc:ID - Invoice number
     * cbc:IssueDate - Invoice issue date
     * cbc:InvoiceTypeCode - Invoice type code
     * cbc:DocumentCurrencyCode - Invoice currency code
     * cac:AccountingSupplierParty - SELLER
     *     cac:Party - PARTY
     *         cbc:EndpointID - Seller electronic address
     *         cac:PostalAddress - SELLER POSTAL ADDRESS
     *         cac:PartyLegalEntity - PARTY LEGAL ENTITY
     *             cbc:RegistrationName - Seller name
     * cac:AccountingCustomerParty - BUYER
     *     cac:Party - PARTY
     *         cbc:EndpointID - Buyer electronic address
     *         cac:PostalAddress - BUYER POSTAL ADDRESS
     *         cac:PartyLegalEntity - PARTY LEGAL ENTITY
     *             cbc:RegistrationName - Buyer name
     * cac:TaxTotal - TAX TOTAL
     *     cbc:TaxAmount - Invoice total VAT amount, Invoice total VAT amount in accounting currency
     * cac:LegalMonetaryTotal - DOCUMENT TOTALS
     *     cbc:LineExtensionAmount - Sum of Invoice line net amount
     *     cbc:TaxExclusiveAmount - Invoice total amount without VAT
     *     cbc:TaxInclusiveAmount - Invoice total amount with VAT
     *     cbc:PayableAmount - Amount due for payment
     * cac:InvoiceLine - INVOICE LINE
     *     cbc:ID - Invoice line identifier
     *     cbc:InvoicedQuantity - Invoiced quantity
     *     cbc:LineExtensionAmount - Invoice line net amount
     *     cac:Item - ITEM INFORMATION
     *         cbc:Name - Item name
     *         cac:ClassifiedTaxCategory - LINE VAT INFORMATION
     *             cbc:ID - Invoiced item VAT category code
     *     cac:Price - PRICE DETAILS
     *         cbc:PriceAmount - Item net price
     * 
     * 
     * Summerized inputs from the minimum above:
     * Invoice number
     * Invoice type code
     * Invoice currency code
     * Supplier
     *     Seller electronic address
     *     SELLER POSTAL ADDRESS
     *     Seller name
     * Customer
     *     Buyer electronic address
     *     BUYER POSTAL ADDRESS
     *     Buyer name
     * VAT amount
     * Invoice lines
     *     Invoiced quantity
     *     Item name
     *     Invoiced item VAT category code
     *     Item net price
     */

    private $UBLVersionID = null;

    #[SerializedName("CustomizationID")]
    #[XmlElement(cdata: false, namespace: Schema::CBC)]
    #[Type("string")]
    private $customizationID = '1.0';

    #[SerializedName("ID")]
    #[XmlElement(cdata: false, namespace: Schema::CBC)]
    #[Type("string")]
    private $id;

    #[SerializedName("IssueDate")]
    #[XmlElement(cdata: false, namespace: Schema::CBC)]
    #[Type("DateTime<'Y-m-d'>")]
    private DateTime $issueDate;

    #[SerializedName("DueDate")]
    #[XmlElement(cdata: false, namespace: Schema::CBC)]
    #[Type("DateTime<'Y-m-d'>")]
    private ?DateTime $dueDate = null;

    #[SerializedName("InvoiceTypeCode")]
    #[XmlElement(cdata: false, namespace: Schema::CBC)]
    #[Type("string")]
    private $invoiceTypeCode = InvoiceTypeCode::INVOICE;

    #[SerializedName("DocumentCurrencyCode")]
    #[XmlElement(cdata: false, namespace: Schema::CBC)]
    #[Type("string")]
    private $documentCurrencyCode = 'RON';

    #[SerializedName("TaxCurrencyCode")]
    #[XmlElement(cdata: false, namespace: Schema::CBC)]
    #[Type("string")]
    private $taxCurrencyCode = 'RON';



    #[SerializedName("Note")]
    #[XmlElement(cdata: false, namespace: Schema::CBC)]
    #[Type("string")]
    private $note;

    #[SerializedName("TaxPointDate")]
    #[XmlElement(cdata: false, namespace: Schema::CBC)]
    #[Type("DateTime<'Y-m-d'>")]
    private $taxPointDate;



    #[SerializedName("PaymentTerms")]
    #[XmlElement(cdata: false, namespace: Schema::CAC)]
    #[Type(PaymentTerms::class)]
    private $paymentTerms;

    #[SerializedName("AccountingSupplierParty")]
    #[XmlElement(cdata: false, namespace: Schema::CAC)]
    #[Type(AccountParty::class)]
    private AccountParty $accountingSupplierParty;

    #[SerializedName("AccountingCustomerParty")]
    #[XmlElement(cdata: false, namespace: Schema::CAC)]
    #[Type(AccountParty::class)]
    private AccountParty $accountingCustomerParty;
    private $supplierAssignedAccountID;

    #[XmlList(inline: true, entry: "PaymentMeans", namespace: Schema::CAC)]
    #[Type("array<App\Invoice\Payment\PaymentMeans>")]
    private $paymentMeans = [];

    #[XmlList(inline: true, entry: "AllowanceCharge", namespace: Schema::CAC)]
    #[Type("array<App\Invoice\AllowanceCharge>")]
    private array $allowanceCharges = [];

    #[SerializedName("TaxTotal")]
    #[XmlElement(cdata: false, namespace: Schema::CAC)]
    #[Type(TaxTotal::class)]
    private $taxTotal;

    #[SerializedName("TaxTotal")]
    #[XmlElement(cdata: false, namespace: Schema::CAC)]
    #[Type(TaxTotal::class)]
    #[Exclude]
    private $taxTotalInLocalCurrency;

    #[SerializedName("LegalMonetaryTotal")]
    #[XmlElement(cdata: false, namespace: Schema::CAC)]
    #[Type(LegalMonetaryTotal::class)]
    private $legalMonetaryTotal;


    #[XmlList(inline: true, entry: "InvoiceLine", namespace: Schema::CAC)]
    #[Type("array<App\Invoice\Invoice\InvoiceLine>")]

    private array $invoiceLines = [];

    private $additionalDocumentReferences;

    #[SerializedName("BuyerReference")]
    #[XmlElement(cdata: false, namespace: Schema::CBC)]
    #[Type("string")]
    private $buyerReference;

    #[SerializedName("AccountingCost")]
    #[XmlElement(cdata: false, namespace: Schema::CBC)]
    #[Type("string")]
    private $accountingCostCode;

    #[SerializedName("InvoicePeriod")]
    #[XmlElement(cdata: false, namespace: Schema::CAC)]
    #[Type(InvoicePeriod::class)]
    private $invoicePeriod;

    #[SerializedName("Delivery")]
    #[XmlElement(cdata: false, namespace: Schema::CAC)]
    #[Type(Delivery::class)]
    private $delivery;

    #[SerializedName("OrderReference")]
    #[XmlElement(cdata: false, namespace: Schema::CAC)]
    #[Type(OrderReference::class)]
    private $orderReference;

    #[SerializedName("ContractDocumentReference")]
    #[XmlElement(cdata: false, namespace: Schema::CAC)]
    #[Type(ContractDocumentReference::class)]
    private $contractDocumentReference;

    /**
     * @return string
     */
    public function getUBLVersionID(): ?string
    {
        return $this->UBLVersionID;
    }

    /**
     * @param string $UBLVersionID
     * eg. '2.0', '2.1', '2.2', ...
     * @return Invoice
     */
    public function setUBLVersionID(?string $UBLVersionID): Invoice
    {
        $this->UBLVersionID = $UBLVersionID;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getId(): ?string
    {
        return $this->id;
    }

    /**
     * @param mixed $id
     * @return Invoice
     */
    public function setId(?string $id): Invoice
    {
        $this->id = $id;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getCustomizationID(): ?string
    {
        return $this->customizationID;
    }

    /**
     * @param mixed $customizationID
     * @return Invoice
     */
    public function setCustomizationID(?string $customizationID): Invoice
    {
        $this->customizationID = $customizationID;
        return $this;
    }

    /**
     * @return DateTime
     */
    public function getIssueDate(): ?DateTime
    {
        return $this->issueDate;
    }

    /**
     * @param DateTime $issueDate
     * @return Invoice
     */
    public function setIssueDate(DateTime $issueDate): Invoice
    {
        $this->issueDate = $issueDate;
        return $this;
    }

    /**
     * @return DateTime
     */
    public function getDueDate(): ?DateTime
    {
        return $this->dueDate;
    }

    /**
     * @param DateTime $dueDate
     * @return Invoice
     */
    public function setDueDate(DateTime $dueDate): Invoice
    {
        $this->dueDate = $dueDate;
        return $this;
    }

    /**
     * @param mixed $currencyCode
     * @return Invoice
     */
    public function setDocumentCurrencyCode(string $currencyCode = 'RON'): Invoice
    {
        $this->documentCurrencyCode = $currencyCode;
        return $this;
    }
    public function getDocumentCurrencyCode(): ?string
    {
        return $this->documentCurrencyCode;
    }

    /**
     * @param mixed $taxCurrencyCode
     * @return Invoice
     */
    public function setTaxCurrencyCode($taxCurrencyCode): self
    {
        $this->taxCurrencyCode = $taxCurrencyCode;

        return $this;
    }

    /**
     * @return string
     */
    public function getInvoiceTypeCode(): ?string
    {
        return $this->invoiceTypeCode;
    }

    /**
     * @param string $invoiceTypeCode
     * See also: src/InvoiceTypeCode.php
     * @return Invoice
     */
    public function setInvoiceTypeCode(string $invoiceTypeCode): Invoice
    {
        $this->invoiceTypeCode = $invoiceTypeCode;
        return $this;
    }

    /**
     * @return string
     */
    public function getNote()
    {
        return $this->note;
    }

    /**
     * @param string $note
     * @return Invoice
     */
    public function setNote(string $note)
    {
        $this->note = $note;
        return $this;
    }

    /**
     * @return DateTime
     */
    public function getTaxPointDate(): ?DateTime
    {
        return $this->taxPointDate;
    }

    /**
     * @param DateTime $taxPointDate
     * @return Invoice
     */
    public function setTaxPointDate(DateTime $taxPointDate): Invoice
    {
        $this->taxPointDate = $taxPointDate;
        return $this;
    }

    /**
     * @return PaymentTerms
     */
    public function getPaymentTerms(): ?PaymentTerms
    {
        return $this->paymentTerms;
    }

    /**
     * @param PaymentTerms $paymentTerms
     * @return Invoice
     */
    public function setPaymentTerms(PaymentTerms $paymentTerms): Invoice
    {
        $this->paymentTerms = $paymentTerms;
        return $this;
    }

    /**
     * @return AccountParty
     */
    public function getAccountingSupplierParty(): ?AccountParty
    {
        return $this->accountingSupplierParty;
    }

    /**
     * @param AccountParty $accountingSupplierParty
     * @return Invoice
     */
    public function setAccountingSupplierParty(AccountParty $accountingSupplierParty): Invoice
    {
        $this->accountingSupplierParty = $accountingSupplierParty;
        return $this;
    }

    /**
     * @return Party
     */
    public function getSupplierAssignedAccountID(): ?string
    {
        return $this->supplierAssignedAccountID;
    }

    /**
     * @param string $supplierAssignedAccountID
     * @return Invoice
     */
    public function setSupplierAssignedAccountID(string $supplierAssignedAccountID): Invoice
    {
        $this->supplierAssignedAccountID = $supplierAssignedAccountID;
        return $this;
    }

    /**
     * @return AccountParty
     */
    public function getAccountingCustomerParty(): ?AccountParty
    {
        return $this->accountingCustomerParty;
    }

    /**
     * @param AccountParty $accountingCustomerParty
     * @return Invoice
     */
    public function setAccountingCustomerParty(AccountParty $accountingCustomerParty): Invoice
    {
        $this->accountingCustomerParty = $accountingCustomerParty;
        return $this;
    }

    /**
     * @return PaymentMeans[]
     */
    public function getPaymentMeans(): array
    {
        return $this->paymentMeans;
    }

    /**
     * @param PaymentMeans[] $paymentMeans
     * @return Invoice
     */
    public function setPaymentMeans(array $paymentMeans): Invoice
    {
        $this->paymentMeans = $paymentMeans;
        return $this;
    }

    /**
     * @return TaxTotal
     */
    public function getTaxTotal(): ?TaxTotal
    {
        return $this->taxTotal;
    }

    /**
     * @param TaxTotal $taxTotal
     * @return Invoice
     */
    public function setTaxTotal(TaxTotal $taxTotal): Invoice
    {
        $this->taxTotal = $taxTotal;
        return $this;
    }


    /**
     * Get the value of taxTotalInLocalCurrency
     */
    public function getTaxTotalInLocalCurrency(): ?TaxTotal
    {
        return $this->taxTotalInLocalCurrency;
    }

    /**
     * @param TaxTotal $taxTotalInLocalCurrency
     * @return Invoice
     */
    public function setTaxTotalInLocalCurrency(TaxTotal $taxTotalInLocalCurrency): Invoice
    {
        $this->taxTotalInLocalCurrency = $taxTotalInLocalCurrency;

        return $this;
    }

    /**
     * @return LegalMonetaryTotal
     */
    public function getLegalMonetaryTotal(): ?LegalMonetaryTotal
    {
        return $this->legalMonetaryTotal;
    }

    /**
     * @param LegalMonetaryTotal $legalMonetaryTotal
     * @return Invoice
     */
    public function setLegalMonetaryTotal(LegalMonetaryTotal $legalMonetaryTotal): Invoice
    {
        $this->legalMonetaryTotal = $legalMonetaryTotal;
        return $this;
    }

    /**
     * @return InvoiceLine[]
     */
    public function getInvoiceLines(): ?array
    {
        return $this->invoiceLines;
    }

    /**
     * @param InvoiceLine[] $invoiceLines
     */
    public function setInvoiceLines(array $invoiceLines): Invoice
    {
        $this->invoiceLines = $invoiceLines;
        return $this;
    }

    /**
     * @return AllowanceCharge[]
     */
    public function getAllowanceCharges(): array
    {
        return $this->allowanceCharges;
    }

    /**
     * @param AllowanceCharge[] $allowanceCharges
     * @return Invoice
     */
    public function setAllowanceCharges(AllowanceCharge $allowanceCharges): Invoice
    {
        $this->allowanceCharges = $allowanceCharges;
        return $this;
    }

    /**
     * @return AdditionalDocumentReference
     */
    public function getAdditionalDocumentReference(): ?AdditionalDocumentReference
    {
        return $this->additionalDocumentReferences;
    }

    /**
     * @param AdditionalDocumentReference $additionalDocumentReference
     * @return Invoice
     */
    public function setAdditionalDocumentReference(AdditionalDocumentReference $additionalDocumentReferences): Invoice
    {
        $this->additionalDocumentReferences = $additionalDocumentReferences;
        return $this;
    }

    /**
     * @param string $buyerReference
     * @return Invoice
     */
    public function setBuyerReference(string $buyerReference): Invoice
    {
        $this->buyerReference = $buyerReference;
        return $this;
    }

    /**
     * @return string buyerReference
     */
    public function getBuyerReference(): ?string
    {
        return $this->buyerReference;
    }

    /**
     * @return mixed
     */
    public function getAccountingCostCode(): ?string
    {
        return $this->accountingCostCode;
    }

    /**
     * @param mixed $accountingCostCode
     * @return Invoice
     */
    public function setAccountingCostCode(string $accountingCostCode): Invoice
    {
        $this->accountingCostCode = $accountingCostCode;
        return $this;
    }

    /**
     * @return InvoicePeriod
     */
    public function getInvoicePeriod(): ?InvoicePeriod
    {
        return $this->invoicePeriod;
    }

    /**
     * @param InvoicePeriod $invoicePeriod
     * @return Invoice
     */
    public function setInvoicePeriod(InvoicePeriod $invoicePeriod): Invoice
    {
        $this->invoicePeriod = $invoicePeriod;
        return $this;
    }

    /**
     * @return Delivery
     */
    public function getDelivery(): ?Delivery
    {
        return $this->delivery;
    }

    /**
     * @param Delivery $delivery
     * @return Invoice
     */
    public function setDelivery(Delivery $delivery): Invoice
    {
        $this->delivery = $delivery;
        return $this;
    }

    /**
     * @return OrderReference
     */
    public function getOrderReference(): ?OrderReference
    {
        return $this->orderReference;
    }

    /**
     * @param OrderReference $orderReference
     * @return Invoice
     */
    public function setOrderReference(OrderReference $orderReference): Invoice
    {
        $this->orderReference = $orderReference;
        return $this;
    }

    /**
     * @return ContractDocumentReference
     */
    public function getContractDocumentReference(): ?ContractDocumentReference
    {
        return $this->contractDocumentReference;
    }

    /**
     * @param string $ContractDocumentReference
     * @return Invoice
     */
    public function setContractDocumentReference(ContractDocumentReference $contractDocumentReference): Invoice
    {
        $this->contractDocumentReference = $contractDocumentReference;
        return $this;
    }

    /**
     * The validate function that is called during xml writing to valid the data of the object.
     *
     * @return void
     * @throws InvalidArgumentException An error with information about required data that is missing to write the XML
     */
    public function validate()
    {
        if ($this->id === null) {
            throw new InvalidArgumentException('Missing invoice id');
        }

        if (!$this->issueDate instanceof DateTime) {
            throw new InvalidArgumentException('Invalid invoice issueDate');
        }

        if ($this->invoiceTypeCode === null) {
            throw new InvalidArgumentException('Missing invoice invoiceTypeCode');
        }

        if ($this->accountingSupplierParty === null) {
            throw new InvalidArgumentException('Missing invoice accountingSupplierParty');
        }

        if ($this->accountingCustomerParty === null) {
            throw new InvalidArgumentException('Missing invoice accountingCustomerParty');
        }

        if ($this->invoiceLines === null) {
            throw new InvalidArgumentException('Missing invoice lines');
        }

        if ($this->legalMonetaryTotal === null) {
            throw new InvalidArgumentException('Missing invoice LegalMonetaryTotal');
        }
    }
}
