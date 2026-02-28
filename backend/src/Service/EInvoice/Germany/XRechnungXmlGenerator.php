<?php

namespace App\Service\EInvoice\Germany;

use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Enum\DocumentType;
use App\Enum\InvoiceTypeCode;
use Doctrine\ORM\EntityManagerInterface;

class XRechnungXmlGenerator
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /**
     * Generate a UBL 2.1 compliant XML with XRechnung 3.0 customization.
     */
    public function generate(Invoice $invoice): string
    {
        $company = $invoice->getCompany();

        // Temporarily disable soft-delete filter so we can load soft-deleted clients
        $filters = $this->entityManager->getFilters();
        $filterWasEnabled = $filters->isEnabled('soft_delete');
        if ($filterWasEnabled) {
            $filters->disable('soft_delete');
        }
        $client = $invoice->getClient();
        if ($filterWasEnabled) {
            $filters->enable('soft_delete');
        }

        $documentType = $invoice->getDocumentType();

        if ($documentType === DocumentType::PROFORMA) {
            throw new \InvalidArgumentException('Proforma invoices cannot be submitted as XRechnung.');
        }

        $isCreditNote = $documentType === DocumentType::CREDIT_NOTE;

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        // Root element with UBL namespaces
        if ($isCreditNote) {
            $root = $dom->createElementNS(
                'urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2',
                'CreditNote'
            );
        } else {
            $root = $dom->createElementNS(
                'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2',
                'Invoice'
            );
        }
        $root->setAttributeNS(
            'http://www.w3.org/2000/xmlns/',
            'xmlns:cac',
            'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2'
        );
        $root->setAttributeNS(
            'http://www.w3.org/2000/xmlns/',
            'xmlns:cbc',
            'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2'
        );
        $dom->appendChild($root);

        // === Header ===

        $this->addElement($dom, $root, 'cbc:CustomizationID', 'urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0');
        $this->addElement($dom, $root, 'cbc:ProfileID', 'urn:fdc:peppol.eu:2017:poacc:billing:01:1.0');

        $this->addElement($dom, $root, 'cbc:ID', $invoice->getNumber() ?? '');
        $this->addElement($dom, $root, 'cbc:IssueDate', $invoice->getIssueDate()?->format('Y-m-d') ?? '');

        if (!$isCreditNote && $invoice->getDueDate() !== null) {
            $this->addElement($dom, $root, 'cbc:DueDate', $invoice->getDueDate()->format('Y-m-d'));
        }

        $typeCode = $this->resolveTypeCode($invoice, $isCreditNote);
        $typeCodeTag = $isCreditNote ? 'cbc:CreditNoteTypeCode' : 'cbc:InvoiceTypeCode';
        $this->addElement($dom, $root, $typeCodeTag, $typeCode);

        if ($invoice->getNotes()) {
            $this->addElement($dom, $root, 'cbc:Note', $invoice->getNotes());
        }

        $this->addElement($dom, $root, 'cbc:DocumentCurrencyCode', $invoice->getCurrency());

        // [BR-DE-1] BuyerReference is mandatory for XRechnung
        $buyerReference = $this->resolveBuyerReference($client);
        $this->addElement($dom, $root, 'cbc:BuyerReference', $buyerReference);

        // === Document References ===

        if ($invoice->getOrderNumber()) {
            $orderRef = $dom->createElement('cac:OrderReference');
            $this->addElement($dom, $orderRef, 'cbc:ID', $invoice->getOrderNumber());
            $root->appendChild($orderRef);
        }

        $parentDoc = $invoice->getParentDocument();
        if ($parentDoc !== null) {
            $billingRef = $dom->createElement('cac:BillingReference');
            $invoiceDocRef = $dom->createElement('cac:InvoiceDocumentReference');
            $this->addElement($dom, $invoiceDocRef, 'cbc:ID', $parentDoc->getNumber() ?? '');
            if ($parentDoc->getIssueDate() !== null) {
                $this->addElement($dom, $invoiceDocRef, 'cbc:IssueDate', $parentDoc->getIssueDate()->format('Y-m-d'));
            }
            $billingRef->appendChild($invoiceDocRef);
            $root->appendChild($billingRef);
        }

        if ($invoice->getContractNumber()) {
            $contractRef = $dom->createElement('cac:ContractDocumentReference');
            $this->addElement($dom, $contractRef, 'cbc:ID', $invoice->getContractNumber());
            $root->appendChild($contractRef);
        }

        // === Parties ===

        $this->addSupplierParty($dom, $root, $company);
        $this->addCustomerParty($dom, $root, $client);

        // === Payment ===

        $this->addPaymentMeans($dom, $root, $invoice, $company);
        $this->addPaymentTerms($dom, $root, $invoice, $isCreditNote);

        // === Tax ===

        $this->addTaxTotal($dom, $root, $invoice);
        $this->addLegalMonetaryTotal($dom, $root, $invoice);

        // === Lines ===

        $lineIndex = 1;
        foreach ($invoice->getLines() as $line) {
            $this->addLine($dom, $root, $line, $lineIndex, $invoice->getCurrency(), $isCreditNote);
            $lineIndex++;
        }

        return $dom->saveXML();
    }

    private function resolveTypeCode(Invoice $invoice, bool $isCreditNote): string
    {
        if ($isCreditNote) {
            return '381';
        }

        $typeCode = $invoice->getInvoiceTypeCode();
        if ($typeCode !== null && $typeCode !== '') {
            $enum = InvoiceTypeCode::tryFrom($typeCode);
            if ($enum !== null) {
                return $enum->untdidCode();
            }
        }

        return '380';
    }

    /**
     * [BR-DE-1] Resolve BuyerReference — Leitweg-ID for B2G, or fallback.
     */
    private function resolveBuyerReference($client): string
    {
        if ($client === null) {
            return 'N/A';
        }

        $identifiers = $client->getEinvoiceIdentifier('xrechnung');
        if ($identifiers !== null && !empty($identifiers['leitwegId'])) {
            return $identifiers['leitwegId'];
        }

        // Fallback: use client code or CUI
        return $client->getClientCode() ?? $client->getCui() ?? 'N/A';
    }

    private function addSupplierParty(\DOMDocument $dom, \DOMElement $root, $company): void
    {
        $supplierParty = $dom->createElement('cac:AccountingSupplierParty');
        $root->appendChild($supplierParty);
        $party = $dom->createElement('cac:Party');
        $supplierParty->appendChild($party);

        if ($company === null) {
            return;
        }

        // EndpointID with electronic address scheme
        $endpointId = $dom->createElement('cbc:EndpointID', $company->getEmail() ?? '');
        $endpointId->setAttribute('schemeID', 'EM'); // Email
        $party->appendChild($endpointId);

        // Party name
        $partyName = $dom->createElement('cac:PartyName');
        $this->addElement($dom, $partyName, 'cbc:Name', $company->getName() ?? '');
        $party->appendChild($partyName);

        // Postal address
        $postalAddress = $dom->createElement('cac:PostalAddress');
        $this->addElement($dom, $postalAddress, 'cbc:StreetName', $company->getAddress() ?? '');
        $this->addElement($dom, $postalAddress, 'cbc:CityName', $company->getCity() ?? '');

        $country = $dom->createElement('cac:Country');
        $this->addElement($dom, $country, 'cbc:IdentificationCode', $company->getCountry() ?? 'DE');
        $postalAddress->appendChild($country);

        $party->appendChild($postalAddress);

        // PartyTaxScheme — VAT ID with country prefix
        $partyTaxScheme = $dom->createElement('cac:PartyTaxScheme');
        $companyId = ($company->getCountry() ?? 'DE') . $company->getCif();
        $this->addElement($dom, $partyTaxScheme, 'cbc:CompanyID', $companyId);
        $taxScheme = $dom->createElement('cac:TaxScheme');
        $this->addElement($dom, $taxScheme, 'cbc:ID', 'VAT');
        $partyTaxScheme->appendChild($taxScheme);
        $party->appendChild($partyTaxScheme);

        // Legal entity
        $legalEntity = $dom->createElement('cac:PartyLegalEntity');
        $this->addElement($dom, $legalEntity, 'cbc:RegistrationName', $company->getName() ?? '');
        $this->addElement($dom, $legalEntity, 'cbc:CompanyID', (string) $company->getCif());
        if ($company->getRegistrationNumber()) {
            $this->addElement($dom, $legalEntity, 'cbc:CompanyLegalForm', $company->getRegistrationNumber());
        }
        $party->appendChild($legalEntity);

        // [BR-DE-5/6/7] Contact is mandatory in XRechnung — Name, Telephone, ElectronicMail
        $contact = $dom->createElement('cac:Contact');
        $this->addElement($dom, $contact, 'cbc:Name', $company->getName() ?? '');
        $this->addElement($dom, $contact, 'cbc:Telephone', $company->getPhone() ?? '');
        $this->addElement($dom, $contact, 'cbc:ElectronicMail', $company->getEmail() ?? '');
        $party->appendChild($contact);
    }

    private function addCustomerParty(\DOMDocument $dom, \DOMElement $root, $client): void
    {
        $customerParty = $dom->createElement('cac:AccountingCustomerParty');
        $root->appendChild($customerParty);
        $clientPartyEl = $dom->createElement('cac:Party');
        $customerParty->appendChild($clientPartyEl);

        if ($client === null) {
            return;
        }

        // EndpointID — Leitweg-ID (scheme 0204) or email
        $identifiers = $client->getEinvoiceIdentifier('xrechnung');
        if ($identifiers !== null && !empty($identifiers['leitwegId'])) {
            $endpointId = $dom->createElement('cbc:EndpointID', $identifiers['leitwegId']);
            $endpointId->setAttribute('schemeID', '0204'); // Leitweg-ID
        } else {
            $endpointId = $dom->createElement('cbc:EndpointID', $client->getEmail() ?? '');
            $endpointId->setAttribute('schemeID', 'EM'); // Email
        }
        $clientPartyEl->appendChild($endpointId);

        // Party name
        $clientPartyName = $dom->createElement('cac:PartyName');
        $this->addElement($dom, $clientPartyName, 'cbc:Name', $client->getName() ?? '');
        $clientPartyEl->appendChild($clientPartyName);

        // Postal address
        $clientAddress = $dom->createElement('cac:PostalAddress');
        $this->addElement($dom, $clientAddress, 'cbc:StreetName', $client->getAddress() ?? '');
        $this->addElement($dom, $clientAddress, 'cbc:CityName', $client->getCity() ?? '');

        if ($client->getPostalCode()) {
            $this->addElement($dom, $clientAddress, 'cbc:PostalZone', $client->getPostalCode());
        }

        $clientCountryCode = $client->getCountry() ?? 'DE';
        $clientCountryEl = $dom->createElement('cac:Country');
        $this->addElement($dom, $clientCountryEl, 'cbc:IdentificationCode', $clientCountryCode);
        $clientAddress->appendChild($clientCountryEl);

        $clientPartyEl->appendChild($clientAddress);

        // PartyTaxScheme
        if ($client->getCui()) {
            $clientTaxScheme = $dom->createElement('cac:PartyTaxScheme');
            $clientCountryPrefix = $clientCountryCode ?: 'DE';
            $clientCompanyId = $clientCountryPrefix . $client->getCui();
            $this->addElement($dom, $clientTaxScheme, 'cbc:CompanyID', $clientCompanyId);
            $clientTaxSch = $dom->createElement('cac:TaxScheme');
            $this->addElement($dom, $clientTaxSch, 'cbc:ID', 'VAT');
            $clientTaxScheme->appendChild($clientTaxSch);
            $clientPartyEl->appendChild($clientTaxScheme);
        }

        // Legal entity
        $clientLegal = $dom->createElement('cac:PartyLegalEntity');
        $this->addElement($dom, $clientLegal, 'cbc:RegistrationName', $client->getName() ?? '');
        if ($client->getCui()) {
            $this->addElement($dom, $clientLegal, 'cbc:CompanyID', $client->getCui());
        }
        $clientPartyEl->appendChild($clientLegal);

        // Contact
        if ($client->getEmail() || $client->getPhone()) {
            $clientContact = $dom->createElement('cac:Contact');
            if ($client->getPhone()) {
                $this->addElement($dom, $clientContact, 'cbc:Telephone', $client->getPhone());
            }
            if ($client->getEmail()) {
                $this->addElement($dom, $clientContact, 'cbc:ElectronicMail', $client->getEmail());
            }
            $clientPartyEl->appendChild($clientContact);
        }
    }

    private function addPaymentMeans(\DOMDocument $dom, \DOMElement $root, Invoice $invoice, $company): void
    {
        // [BR-DE-1] PaymentMeans is mandatory in XRechnung
        $paymentMeans = $dom->createElement('cac:PaymentMeans');
        $paymentMeansCode = $this->mapPaymentMethod($invoice->getPaymentMethod());
        $this->addElement($dom, $paymentMeans, 'cbc:PaymentMeansCode', $paymentMeansCode);

        // [BR-DE-2] Payment account (IBAN)
        if ($company?->getBankAccount()) {
            $payeeAccount = $dom->createElement('cac:PayeeFinancialAccount');
            $this->addElement($dom, $payeeAccount, 'cbc:ID', $company->getBankAccount());
            if ($company->getBankName()) {
                $this->addElement($dom, $payeeAccount, 'cbc:Name', $company->getBankName());
            }
            if ($company->getBankBic()) {
                $branch = $dom->createElement('cac:FinancialInstitutionBranch');
                $this->addElement($dom, $branch, 'cbc:ID', $company->getBankBic());
                $payeeAccount->appendChild($branch);
            }
            $paymentMeans->appendChild($payeeAccount);
        }

        $root->appendChild($paymentMeans);
    }

    private function addPaymentTerms(\DOMDocument $dom, \DOMElement $root, Invoice $invoice, bool $isCreditNote): void
    {
        $termsNote = $invoice->getPaymentTerms();

        $hasDueDateInXml = !$isCreditNote && $invoice->getDueDate() !== null;

        if (!$termsNote && !$hasDueDateInXml) {
            $termsNote = 'Payment due upon receipt';
        }

        if ($termsNote) {
            $paymentTerms = $dom->createElement('cac:PaymentTerms');
            $this->addElement($dom, $paymentTerms, 'cbc:Note', $termsNote);
            $root->appendChild($paymentTerms);
        }
    }

    private function addTaxTotal(\DOMDocument $dom, \DOMElement $root, Invoice $invoice): void
    {
        $taxTotal = $dom->createElement('cac:TaxTotal');
        $taxAmountEl = $dom->createElement('cbc:TaxAmount', $invoice->getVatTotal());
        $taxAmountEl->setAttribute('currencyID', $invoice->getCurrency());
        $taxTotal->appendChild($taxAmountEl);

        // Group lines by VAT category code + rate for TaxSubtotal breakdown
        $vatGroups = [];
        foreach ($invoice->getLines() as $line) {
            $key = $line->getVatCategoryCode() . '_' . $line->getVatRate();
            if (!isset($vatGroups[$key])) {
                $vatGroups[$key] = [
                    'categoryCode' => $line->getVatCategoryCode(),
                    'rate' => $line->getVatRate(),
                    'taxableAmount' => '0.00',
                    'taxAmount' => '0.00',
                ];
            }
            $vatGroups[$key]['taxableAmount'] = bcadd($vatGroups[$key]['taxableAmount'], $line->getLineTotal(), 2);
            $vatGroups[$key]['taxAmount'] = bcadd($vatGroups[$key]['taxAmount'], $line->getVatAmount(), 2);
        }

        foreach ($vatGroups as $group) {
            $taxSubtotal = $dom->createElement('cac:TaxSubtotal');

            $taxableAmountEl = $dom->createElement('cbc:TaxableAmount', $group['taxableAmount']);
            $taxableAmountEl->setAttribute('currencyID', $invoice->getCurrency());
            $taxSubtotal->appendChild($taxableAmountEl);

            $subTaxAmountEl = $dom->createElement('cbc:TaxAmount', $group['taxAmount']);
            $subTaxAmountEl->setAttribute('currencyID', $invoice->getCurrency());
            $taxSubtotal->appendChild($subTaxAmountEl);

            $taxCat = $dom->createElement('cac:TaxCategory');
            $this->addElement($dom, $taxCat, 'cbc:ID', $group['categoryCode']);
            $this->addElement($dom, $taxCat, 'cbc:Percent', $group['rate']);

            $exemptionReason = $this->getVatExemptionReason($group['categoryCode']);
            if ($exemptionReason !== null) {
                $this->addElement($dom, $taxCat, 'cbc:TaxExemptionReason', $exemptionReason);
            }

            $catTaxScheme = $dom->createElement('cac:TaxScheme');
            $this->addElement($dom, $catTaxScheme, 'cbc:ID', 'VAT');
            $taxCat->appendChild($catTaxScheme);
            $taxSubtotal->appendChild($taxCat);

            $taxTotal->appendChild($taxSubtotal);
        }

        $root->appendChild($taxTotal);
    }

    private function addLegalMonetaryTotal(\DOMDocument $dom, \DOMElement $root, Invoice $invoice): void
    {
        $legalTotal = $dom->createElement('cac:LegalMonetaryTotal');

        $lineExtension = $dom->createElement('cbc:LineExtensionAmount', $invoice->getSubtotal());
        $lineExtension->setAttribute('currencyID', $invoice->getCurrency());
        $legalTotal->appendChild($lineExtension);

        $taxExclusive = $dom->createElement('cbc:TaxExclusiveAmount', $invoice->getSubtotal());
        $taxExclusive->setAttribute('currencyID', $invoice->getCurrency());
        $legalTotal->appendChild($taxExclusive);

        $taxInclusive = $dom->createElement('cbc:TaxInclusiveAmount', $invoice->getTotal());
        $taxInclusive->setAttribute('currencyID', $invoice->getCurrency());
        $legalTotal->appendChild($taxInclusive);

        $payable = $dom->createElement('cbc:PayableAmount', $invoice->getTotal());
        $payable->setAttribute('currencyID', $invoice->getCurrency());
        $legalTotal->appendChild($payable);

        $root->appendChild($legalTotal);
    }

    private function addLine(
        \DOMDocument $dom,
        \DOMElement $root,
        InvoiceLine $line,
        int $index,
        string $currency,
        bool $isCreditNote,
    ): void {
        $lineTag = $isCreditNote ? 'cac:CreditNoteLine' : 'cac:InvoiceLine';
        $invoiceLine = $dom->createElement($lineTag);

        $this->addElement($dom, $invoiceLine, 'cbc:ID', (string) $index);

        $qtyTag = $isCreditNote ? 'cbc:CreditedQuantity' : 'cbc:InvoicedQuantity';
        $quantity = $dom->createElement($qtyTag, $line->getQuantity());
        $quantity->setAttribute('unitCode', $this->mapUnitOfMeasure($line->getUnitOfMeasure()));
        $invoiceLine->appendChild($quantity);

        $lineAmount = $dom->createElement('cbc:LineExtensionAmount', $line->getLineTotal());
        $lineAmount->setAttribute('currencyID', $currency);
        $invoiceLine->appendChild($lineAmount);

        // Item
        $item = $dom->createElement('cac:Item');
        $this->addElement($dom, $item, 'cbc:Name', $line->getDescription() ?? '');

        // Classified tax category
        $taxCategory = $dom->createElement('cac:ClassifiedTaxCategory');
        $this->addElement($dom, $taxCategory, 'cbc:ID', $line->getVatCategoryCode());
        $this->addElement($dom, $taxCategory, 'cbc:Percent', $line->getVatRate());
        $lineTaxScheme = $dom->createElement('cac:TaxScheme');
        $this->addElement($dom, $lineTaxScheme, 'cbc:ID', 'VAT');
        $taxCategory->appendChild($lineTaxScheme);
        $item->appendChild($taxCategory);

        $invoiceLine->appendChild($item);

        // Price
        $price = $dom->createElement('cac:Price');
        $priceAmount = $dom->createElement('cbc:PriceAmount', $line->getUnitPrice());
        $priceAmount->setAttribute('currencyID', $currency);
        $price->appendChild($priceAmount);
        $invoiceLine->appendChild($price);

        $root->appendChild($invoiceLine);
    }

    private function addElement(\DOMDocument $dom, \DOMElement $parent, string $name, string $value): void
    {
        $element = $dom->createElement($name, htmlspecialchars($value, ENT_XML1, 'UTF-8'));
        $parent->appendChild($element);
    }

    private function mapUnitOfMeasure(string $unit): string
    {
        return match (mb_strtolower($unit)) {
            'buc', 'bucata', 'bucati', 'stk', 'stück', 'piece', 'pcs' => 'H87',
            'kg', 'kilogram' => 'KGM',
            'l', 'litru', 'litri', 'liter' => 'LTR',
            'm', 'metru', 'metri', 'meter' => 'MTR',
            'ora', 'ore', 'h', 'stunde', 'hour' => 'HUR',
            'zi', 'zile', 'tag', 'day' => 'DAY',
            'luna', 'luni', 'monat', 'month' => 'MON',
            'set' => 'SET',
            'pachet', 'paket', 'package' => 'PK',
            default => 'H87',
        };
    }

    private function mapPaymentMethod(?string $method): string
    {
        return match ($method) {
            'cash' => '10',
            'cheque' => '20',
            'bank_transfer' => '58',   // SEPA credit transfer
            'card' => '48',
            'direct_debit' => '59',    // SEPA direct debit
            'other' => 'ZZZ',
            default => '58',           // SEPA credit transfer (default for Germany)
        };
    }

    private function getVatExemptionReason(string $categoryCode): ?string
    {
        return match ($categoryCode) {
            'E' => 'Exempt from VAT',
            'AE' => 'Reverse charge',
            'K' => 'Intra-community supply',
            'G' => 'Export outside the EU',
            'O' => 'Not subject to VAT',
            default => null,
        };
    }
}
