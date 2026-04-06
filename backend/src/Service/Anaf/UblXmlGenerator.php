<?php

namespace App\Service\Anaf;

use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Enum\DocumentType;
use App\Enum\InvoiceTypeCode;
use App\Service\ExchangeRateService;
use App\Util\AddressNormalizer;
use Doctrine\ORM\EntityManagerInterface;

class UblXmlGenerator
{
    public function __construct(
        private readonly ExchangeRateService $exchangeRateService,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /**
     * Generate a UBL 2.1 compliant XML from the given Invoice entity.
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

        // [BR-RO-020] Only invoice and credit note types can be submitted to ANAF
        if ($documentType === DocumentType::PROFORMA) {
            throw new \InvalidArgumentException('Proforma invoices cannot be submitted to ANAF e-Factura.');
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

        // === Header (UBL element ordering per XSD) ===

        // [BR-RO-001] UBL version and CIUS-RO specification identifier
        $this->addElement($dom, $root, 'cbc:UBLVersionID', '2.1');
        $this->addElement($dom, $root, 'cbc:CustomizationID', 'urn:cen.eu:en16931:2017#compliant#urn:efactura.mfinante.ro:CIUS-RO:1.0.1');

        // ProfileID (BT-23) — Business process type
        if ($invoice->getBusinessProcessType()) {
            $this->addElement($dom, $root, 'cbc:ProfileID', $invoice->getBusinessProcessType());
        }

        // [BR-RO-010] Invoice number must contain at least one numeric character
        $this->addElement($dom, $root, 'cbc:ID', $invoice->getNumber() ?? '');
        $this->addElement($dom, $root, 'cbc:IssueDate', $invoice->getIssueDate()?->format('Y-m-d') ?? '');

        // TaxPointDate (BT-7)
        if ($invoice->getTaxPointDate() !== null) {
            $this->addElement($dom, $root, 'cbc:TaxPointDate', $invoice->getTaxPointDate()->format('Y-m-d'));
        }

        // DueDate — only on Invoice (CreditNote XSD does not have DueDate)
        if (!$isCreditNote && $invoice->getDueDate() !== null) {
            $this->addElement($dom, $root, 'cbc:DueDate', $invoice->getDueDate()->format('Y-m-d'));
        }

        // [BR-RO-020] InvoiceTypeCode: 380, 384, 389, 751 / CreditNoteTypeCode: 381
        $typeCode = $this->resolveTypeCode($invoice, $isCreditNote);
        $typeCodeTag = $isCreditNote ? 'cbc:CreditNoteTypeCode' : 'cbc:InvoiceTypeCode';
        $this->addElement($dom, $root, $typeCodeTag, $typeCode);

        // Note (BT-22) [BR-RO-A020 max 20 occurrences] [BR-RO-L302 max 300 chars each]
        if ($invoice->getNotes()) {
            $this->addElement($dom, $root, 'cbc:Note', $invoice->getNotes());
        }

        // UBL extensions
        $extensions = $invoice->getUblExtensions() ?? [];

        // InvoicePeriod from ublExtensions (after Note, before DocumentCurrencyCode per XSD)
        if (!empty($extensions['invoicePeriod'])) {
            $this->addInvoicePeriod($dom, $root, $extensions['invoicePeriod']);
        }

        // [BR-RO-030] DocumentCurrencyCode and TaxCurrencyCode
        $this->addElement($dom, $root, 'cbc:DocumentCurrencyCode', $invoice->getCurrency());
        if ($invoice->getCurrency() !== 'RON') {
            $this->addElement($dom, $root, 'cbc:TaxCurrencyCode', 'RON');
        }

        // AccountingCost (BT-19)
        if ($invoice->getBuyerAccountingReference()) {
            $this->addElement($dom, $root, 'cbc:AccountingCost', $invoice->getBuyerAccountingReference());
        }

        // BuyerReference (BT-10)
        if ($invoice->getBuyerReference()) {
            $this->addElement($dom, $root, 'cbc:BuyerReference', $invoice->getBuyerReference());
        }

        // === Document References (UBL ordering: OrderReference → BillingReference → ContractDocumentReference) ===

        // OrderReference (BT-13) [BR-RO-L0303 max 200 chars]
        if ($invoice->getOrderNumber()) {
            $orderRef = $dom->createElement('cac:OrderReference');
            $this->addElement($dom, $orderRef, 'cbc:ID', $invoice->getOrderNumber());
            $root->appendChild($orderRef);
        }

        // BillingReference — required for credit notes referencing the original
        $parentDoc = $invoice->getParentDocument();
        if ($parentDoc !== null && $parentDoc->getNumber()) {
            $billingRef = $dom->createElement('cac:BillingReference');
            $invoiceDocRef = $dom->createElement('cac:InvoiceDocumentReference');
            $this->addElement($dom, $invoiceDocRef, 'cbc:ID', $parentDoc->getNumber() ?? '');
            if ($parentDoc->getIssueDate() !== null) {
                $this->addElement($dom, $invoiceDocRef, 'cbc:IssueDate', $parentDoc->getIssueDate()->format('Y-m-d'));
            }
            $billingRef->appendChild($invoiceDocRef);
            $root->appendChild($billingRef);
        }

        // ContractDocumentReference (BT-12) [BR-RO-L0302 max 200 chars]
        if ($invoice->getContractNumber()) {
            $contractRef = $dom->createElement('cac:ContractDocumentReference');
            $this->addElement($dom, $contractRef, 'cbc:ID', $invoice->getContractNumber());
            $root->appendChild($contractRef);
        }

        // DespatchDocumentReference (BT-16)
        if ($invoice->getDespatchAdviceReference()) {
            $despatchRef = $dom->createElement('cac:DespatchDocumentReference');
            $this->addElement($dom, $despatchRef, 'cbc:ID', $invoice->getDespatchAdviceReference());
            $root->appendChild($despatchRef);
        }

        // ReceiptDocumentReference (BT-15)
        if ($invoice->getReceivingAdviceReference()) {
            $receiptRef = $dom->createElement('cac:ReceiptDocumentReference');
            $this->addElement($dom, $receiptRef, 'cbc:ID', $invoice->getReceivingAdviceReference());
            $root->appendChild($receiptRef);
        }

        // OriginatorDocumentReference (BT-17)
        if ($invoice->getTenderOrLotReference()) {
            $originatorRef = $dom->createElement('cac:OriginatorDocumentReference');
            $this->addElement($dom, $originatorRef, 'cbc:ID', $invoice->getTenderOrLotReference());
            $root->appendChild($originatorRef);
        }

        // AdditionalDocumentReference with DocumentTypeCode=130 (BT-18a)
        if ($invoice->getInvoicedObjectIdentifier()) {
            $additionalRef = $dom->createElement('cac:AdditionalDocumentReference');
            $this->addElement($dom, $additionalRef, 'cbc:ID', $invoice->getInvoicedObjectIdentifier());
            $this->addElement($dom, $additionalRef, 'cbc:DocumentTypeCode', '130');
            $root->appendChild($additionalRef);
        }

        // AdditionalDocumentReferences from ublExtensions
        if (!empty($extensions['additionalDocumentReferences'])) {
            foreach ($extensions['additionalDocumentReferences'] as $ref) {
                $extRef = $dom->createElement('cac:AdditionalDocumentReference');
                $this->addElement($dom, $extRef, 'cbc:ID', $ref['id']);
                if (!empty($ref['documentTypeCode'])) {
                    $this->addElement($dom, $extRef, 'cbc:DocumentTypeCode', $ref['documentTypeCode']);
                }
                if (!empty($ref['documentDescription'])) {
                    $this->addElement($dom, $extRef, 'cbc:DocumentDescription', $ref['documentDescription']);
                }
                $root->appendChild($extRef);
            }
        }

        // ProjectReference (BT-11)
        if ($invoice->getProjectReference()) {
            $projectRef = $dom->createElement('cac:ProjectReference');
            $this->addElement($dom, $projectRef, 'cbc:ID', $invoice->getProjectReference());
            $root->appendChild($projectRef);
        }

        // === Parties ===

        // AccountingSupplierParty (Company)
        $this->addSupplierParty($dom, $root, $company, $invoice);

        // AccountingCustomerParty (Client)
        // Use buyerSnapshot when available (preserves original buyer identity for storno/credit notes)
        $this->addCustomerParty($dom, $root, $client, $invoice->getBuyerSnapshot(), $invoice);

        // Delivery from ublExtensions (after AccountingCustomerParty, before PayeeParty per XSD)
        if (!empty($extensions['delivery'])) {
            $this->addDeliveryElement($dom, $root, $extensions['delivery']);
        }

        // PayeeParty (BT-59, BT-60, BT-61)
        $this->addPayeeParty($dom, $root, $invoice);

        // === Payment (UBL ordering: PaymentMeans BEFORE PaymentTerms) ===

        // PaymentMeans
        $this->addPaymentMeans($dom, $root, $invoice, $company);

        // PaymentTerms [BR-CO-25]
        $this->addPaymentTerms($dom, $root, $invoice, $isCreditNote);

        // Document-level AllowanceCharge from ublExtensions (after PaymentTerms, before TaxTotal per XSD)
        $allowanceTotal = '0.00';
        $chargeTotal = '0.00';
        if (!empty($extensions['allowanceCharges'])) {
            [$allowanceTotal, $chargeTotal] = $this->addDocumentAllowanceCharges($dom, $root, $extensions['allowanceCharges'], $invoice->getCurrency());
        }

        // === Tax ===

        // TaxTotal with VAT breakdown [BR-CO-18] [BR-S-01]
        $this->addTaxTotal($dom, $root, $invoice, $extensions);

        // [BR-RO-030] Second TaxTotal in RON when invoice currency is not RON
        if ($invoice->getCurrency() !== 'RON') {
            $this->addTaxTotalInRon($dom, $root, $invoice, $extensions);
        }

        // Legal monetary total
        $prepaidAmount = $extensions['prepaidAmount'] ?? '0.00';
        $this->addLegalMonetaryTotal($dom, $root, $invoice, $allowanceTotal, $chargeTotal, $prepaidAmount);

        // === Lines ===

        $lineIndex = 1;
        foreach ($invoice->getLines() as $line) {
            $this->addLine($dom, $root, $line, $lineIndex, $invoice->getCurrency(), $isCreditNote);
            $lineIndex++;
        }

        return $dom->saveXML();
    }

    /**
     * [BR-RO-020] Resolve the invoice/credit note type code.
     * [BR-CL-01] Must be a valid UNTDID 1001 code.
     * Invoice: 380 (default), 384 (corrected), 389 (self-invoice), 751 (accounting info)
     * CreditNote: 381
     */
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

    private function addSupplierParty(\DOMDocument $dom, \DOMElement $root, $company, Invoice $invoice): void
    {
        $supplierParty = $dom->createElement('cac:AccountingSupplierParty');
        $root->appendChild($supplierParty);
        $party = $dom->createElement('cac:Party');
        $supplierParty->appendChild($party);

        if ($company === null) {
            return;
        }

        // Party name (BT-28) [BR-RO-L202 max 200 chars]
        $partyName = $dom->createElement('cac:PartyName');
        $this->addElement($dom, $partyName, 'cbc:Name', $company->getName() ?? '');
        $party->appendChild($partyName);

        // [BR-RO-081] Postal address — StreetName required [BR-RO-L151 max 150 chars]
        $postalAddress = $dom->createElement('cac:PostalAddress');
        $this->addElement($dom, $postalAddress, 'cbc:StreetName', $company->getAddress() ?? '');

        // [BR-RO-110] CountrySubentity must be ISO 3166-2:RO when country is RO
        $state = $company->getState() ?? '';
        if ($state !== '' && ($company->getCountry() ?? 'RO') === 'RO') {
            $state = AddressNormalizer::normalizeCounty($state);
        }
        if ($state !== '' && !str_starts_with($state, 'RO-')) {
            $state = 'RO-' . $state;
        }

        // [BR-RO-091] City required [BR-RO-L0501 max 50 chars]
        // [BR-RO-100] Bucharest requires SECTOR1-SECTOR6
        $city = $company->getCity() ?? '';
        if ($state === 'RO-B') {
            $city = AddressNormalizer::normalizeBucharestSector($city);
        }
        $this->addElement($dom, $postalAddress, 'cbc:CityName', $city);
        $this->addElement($dom, $postalAddress, 'cbc:CountrySubentity', $state);

        $country = $dom->createElement('cac:Country');
        $this->addElement($dom, $country, 'cbc:IdentificationCode', $company->getCountry() ?? 'RO');
        $postalAddress->appendChild($country);

        $party->appendChild($postalAddress);

        // [BR-RO-065] PartyTaxScheme — BT-31 and/or BT-32 required
        $isVatPayer = $company->isVatPayer() || $invoice->isPlatitorTva();
        $allO = $this->allLinesAreCategoryO($invoice);
        $countryCode = $company->getCountry() ?? 'RO';
        $cif = (string) $company->getCif();

        // BR-O-02: When all lines are category O, BT-31 (Seller VAT ID) shall NOT be present.
        // Fall back to BT-32 (tax registration without VAT scheme) in that case.
        if ($isVatPayer && !$allO) {
            // BT-31: Seller VAT identifier (TaxScheme/ID = VAT)
            // [BR-CO-09] Must have ISO 3166-1 alpha-2 country prefix
            $partyTaxScheme = $dom->createElement('cac:PartyTaxScheme');
            $this->addElement($dom, $partyTaxScheme, 'cbc:CompanyID', $countryCode . $cif);
            $taxScheme = $dom->createElement('cac:TaxScheme');
            $this->addElement($dom, $taxScheme, 'cbc:ID', 'VAT');
            $partyTaxScheme->appendChild($taxScheme);
            $party->appendChild($partyTaxScheme);
        } else {
            // BT-32: Seller tax registration identifier (TaxScheme/ID != VAT)
            // Non-VAT payers and all-O invoices must NOT declare a VAT identifier
            $partyTaxScheme = $dom->createElement('cac:PartyTaxScheme');
            $this->addElement($dom, $partyTaxScheme, 'cbc:CompanyID', $cif);
            $taxScheme = $dom->createElement('cac:TaxScheme');
            $this->addElement($dom, $taxScheme, 'cbc:ID', 'NOT_VAT');
            $partyTaxScheme->appendChild($taxScheme);
            $party->appendChild($partyTaxScheme);
        }

        // Legal entity (BT-27) [BR-RO-L201 max 200 chars]
        $legalEntity = $dom->createElement('cac:PartyLegalEntity');
        $this->addElement($dom, $legalEntity, 'cbc:RegistrationName', $company->getName() ?? '');
        $this->addElement($dom, $legalEntity, 'cbc:CompanyID', (string) $company->getCif());
        // [BR-RO-L1000] CompanyLegalForm max 1000 chars
        if ($company->getRegistrationNumber()) {
            $this->addElement($dom, $legalEntity, 'cbc:CompanyLegalForm', $company->getRegistrationNumber());
        }
        $party->appendChild($legalEntity);

        // Contact [BR-RO-L1005 phone max 100] [BR-RO-L1006 email max 100]
        if ($company->getEmail() || $company->getPhone()) {
            $contact = $dom->createElement('cac:Contact');
            if ($company->getPhone()) {
                $this->addElement($dom, $contact, 'cbc:Telephone', $company->getPhone());
            }
            if ($company->getEmail()) {
                $this->addElement($dom, $contact, 'cbc:ElectronicMail', $company->getEmail());
            }
            $party->appendChild($contact);
        }
    }

    private function addCustomerParty(\DOMDocument $dom, \DOMElement $root, $client, ?array $buyerSnapshot = null, ?Invoice $invoice = null): void
    {
        $customerParty = $dom->createElement('cac:AccountingCustomerParty');
        $root->appendChild($customerParty);
        $clientPartyEl = $dom->createElement('cac:Party');
        $customerParty->appendChild($clientPartyEl);

        if ($client === null && $buyerSnapshot === null) {
            return;
        }

        // When buyerSnapshot exists, use it exclusively (preserves original buyer identity).
        // Only fall back to live Client entity when no snapshot is available (old invoices).
        if ($buyerSnapshot !== null) {
            $bName = $buyerSnapshot['name'] ?? '';
            $bCui = $buyerSnapshot['cui'] ?? null;
            $bCnp = $buyerSnapshot['cnp'] ?? null;
            $bAddress = $buyerSnapshot['address'] ?? '';
            $bCity = $buyerSnapshot['city'] ?? '';
            $bCounty = $buyerSnapshot['county'] ?? '';
            $bCountry = $buyerSnapshot['country'] ?? 'RO';
            $bPostalCode = $buyerSnapshot['postalCode'] ?? null;
            $bEmail = $buyerSnapshot['email'] ?? null;
            $bPhone = $buyerSnapshot['phone'] ?? null;
            // Snapshot stores isVatPayer; fall back to true when CUI present (legacy snapshots)
            $bIsVatPayer = isset($buyerSnapshot['isVatPayer'])
                ? (bool) $buyerSnapshot['isVatPayer']
                : ($bCui !== null && $bCui !== '');
        } else {
            $bName = $client?->getName() ?? '';
            $bCui = $client?->getCui();
            $bCnp = $client?->getCnp();
            $bAddress = $client?->getAddress() ?? '';
            $bCity = $client?->getCity() ?? '';
            $bCounty = $client?->getCounty() ?? '';
            $bCountry = $client?->getCountry() ?? 'RO';
            $bPostalCode = $client?->getPostalCode();
            $bEmail = $client?->getEmail();
            $bPhone = $client?->getPhone();
            $bIsVatPayer = (bool) $client?->isVatPayer();
        }

        // Party name (BT-45) [BR-RO-L204 max 200 chars]
        $clientPartyName = $dom->createElement('cac:PartyName');
        $this->addElement($dom, $clientPartyName, 'cbc:Name', $bName);
        $clientPartyEl->appendChild($clientPartyName);

        // [BR-RO-082] Postal address — StreetName required [BR-RO-L152 max 150 chars]
        $clientAddress = $dom->createElement('cac:PostalAddress');
        $this->addElement($dom, $clientAddress, 'cbc:StreetName', $bAddress);

        $clientCountryCode = $bCountry;
        $clientCounty = $bCounty;

        // Normalize county to ISO code (handles legacy full names like "Alba" → "AB")
        if ($clientCounty !== '' && $clientCountryCode === 'RO') {
            $clientCounty = AddressNormalizer::normalizeCounty($clientCounty);
        }

        // [BR-RO-111] CountrySubentity required when country is RO (ISO 3166-2:RO)
        if ($clientCounty !== '' && $clientCountryCode === 'RO' && !str_starts_with($clientCounty, 'RO-')) {
            $clientCounty = 'RO-' . $clientCounty;
        }

        // [BR-RO-092] City required [BR-RO-L0502 max 50 chars]
        // [BR-RO-101] Bucharest requires SECTOR1-SECTOR6
        $clientCity = $bCity;
        if ($clientCounty === 'RO-B') {
            $clientCity = AddressNormalizer::normalizeBucharestSector($clientCity);
        }
        $this->addElement($dom, $clientAddress, 'cbc:CityName', $clientCity);

        // PostalZone must come before CountrySubentity per UBL 2.1 schema
        if ($bPostalCode) {
            $this->addElement($dom, $clientAddress, 'cbc:PostalZone', $bPostalCode);
        }

        // [BR-RO-111] CountrySubentity — always required for RO clients
        if ($clientCounty !== '') {
            $this->addElement($dom, $clientAddress, 'cbc:CountrySubentity', $clientCounty);
        }

        $clientCountryEl = $dom->createElement('cac:Country');
        $this->addElement($dom, $clientCountryEl, 'cbc:IdentificationCode', $clientCountryCode);
        $clientAddress->appendChild($clientCountryEl);

        $clientPartyEl->appendChild($clientAddress);

        // [BR-RO-120] Tax scheme (CUI/PartyTaxScheme CompanyID)
        // [BR-CO-09] VAT identifier must always have ISO 3166-1 alpha-2 country prefix
        // BT-48 (TaxScheme/ID=VAT) only when buyer is VAT payer AND invoice is not all-O.
        // BR-O-03: When all lines are category O, BT-48 shall NOT be present.
        $allO = $invoice !== null && $this->allLinesAreCategoryO($invoice);
        if ($bCui && $bIsVatPayer && !$allO) {
            $clientTaxScheme = $dom->createElement('cac:PartyTaxScheme');
            $clientCountryPrefix = $clientCountryCode ?: 'RO';
            $clientCompanyId = $clientCountryPrefix . $bCui;
            $this->addElement($dom, $clientTaxScheme, 'cbc:CompanyID', $clientCompanyId);
            $clientTaxSch = $dom->createElement('cac:TaxScheme');
            $this->addElement($dom, $clientTaxSch, 'cbc:ID', 'VAT');
            $clientTaxScheme->appendChild($clientTaxSch);
            $clientPartyEl->appendChild($clientTaxScheme);
        }

        // Legal entity (BT-44) [BR-RO-L203 max 200 chars]
        $clientLegal = $dom->createElement('cac:PartyLegalEntity');
        $this->addElement($dom, $clientLegal, 'cbc:RegistrationName', $bName);
        if ($bCui) {
            $this->addElement($dom, $clientLegal, 'cbc:CompanyID', $bCui);
        } elseif ($bCnp) {
            $this->addElement($dom, $clientLegal, 'cbc:CompanyID', $bCnp);
        } else {
            // [BR-RO-120] B2C fallback: 13 zeros accepted by ANAF for individuals without CUI/CNP
            $this->addElement($dom, $clientLegal, 'cbc:CompanyID', '0000000000000');
        }
        // Note: CompanyLegalForm is NOT allowed for buyer per [UBL-CR-244]
        $clientPartyEl->appendChild($clientLegal);

        // Contact [BR-RO-L1010 phone max 100] [BR-RO-L1011 email max 100]
        if ($bEmail || $bPhone) {
            $clientContact = $dom->createElement('cac:Contact');
            if ($bPhone) {
                $this->addElement($dom, $clientContact, 'cbc:Telephone', $bPhone);
            }
            if ($bEmail) {
                $this->addElement($dom, $clientContact, 'cbc:ElectronicMail', $bEmail);
            }
            $clientPartyEl->appendChild($clientContact);
        }
    }

    private function addPayeeParty(\DOMDocument $dom, \DOMElement $root, Invoice $invoice): void
    {
        $name = $invoice->getPayeeName();
        $identifier = $invoice->getPayeeIdentifier();
        $legalId = $invoice->getPayeeLegalRegistrationIdentifier();

        if (!$name && !$identifier && !$legalId) {
            return;
        }

        $payeeParty = $dom->createElement('cac:PayeeParty');

        // BT-59 Payee name
        if ($name) {
            $partyName = $dom->createElement('cac:PartyName');
            $this->addElement($dom, $partyName, 'cbc:Name', $name);
            $payeeParty->appendChild($partyName);
        }

        // BT-60 Payee identifier
        if ($identifier) {
            $partyId = $dom->createElement('cac:PartyIdentification');
            $this->addElement($dom, $partyId, 'cbc:ID', $identifier);
            $payeeParty->appendChild($partyId);
        }

        // BT-61 Payee legal registration identifier
        if ($legalId) {
            $legalEntity = $dom->createElement('cac:PartyLegalEntity');
            $this->addElement($dom, $legalEntity, 'cbc:CompanyID', $legalId);
            $payeeParty->appendChild($legalEntity);
        }

        $root->appendChild($payeeParty);
    }

    private function addPaymentMeans(\DOMDocument $dom, \DOMElement $root, Invoice $invoice, $company): void
    {
        if ($company === null || !$company->getBankAccount()) {
            return;
        }

        $paymentMeans = $dom->createElement('cac:PaymentMeans');
        $paymentMeansCode = $this->mapPaymentMethod($invoice->getPaymentMethod());
        $this->addElement($dom, $paymentMeans, 'cbc:PaymentMeansCode', $paymentMeansCode);

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
        $root->appendChild($paymentMeans);
    }

    /**
     * [BR-CO-25] When Amount due for payment > 0, either DueDate or PaymentTerms must be present.
     */
    private function addPaymentTerms(\DOMDocument $dom, \DOMElement $root, Invoice $invoice, bool $isCreditNote): void
    {
        $termsNote = $invoice->getPaymentTerms();

        // CreditNote has no DueDate in XSD, so always needs PaymentTerms
        $hasDueDateInXml = !$isCreditNote && $invoice->getDueDate() !== null;

        if (!$termsNote && !$hasDueDateInXml) {
            $termsNote = 'Plata la emitere';
        }

        if ($termsNote) {
            $paymentTerms = $dom->createElement('cac:PaymentTerms');
            $this->addElement($dom, $paymentTerms, 'cbc:Note', $termsNote);
            $root->appendChild($paymentTerms);
        }
    }

    private function addTaxTotal(\DOMDocument $dom, \DOMElement $root, Invoice $invoice, array $extensions = []): void
    {
        // Group lines by VAT category code + rate for TaxSubtotal breakdown (BG-23)
        $vatGroups = [];
        foreach ($invoice->getLines() as $line) {
            $normalizedCode = $this->normalizeVatCategoryCode($line->getVatCategoryCode(), $line->getVatRate());
            $key = $normalizedCode . '_' . $line->getVatRate();
            if (!isset($vatGroups[$key])) {
                $vatGroups[$key] = [
                    'categoryCode' => $normalizedCode,
                    'rate' => $line->getVatRate(),
                    'taxableAmount' => '0.00',
                    'taxAmount' => '0.00',
                ];
            }
            $vatGroups[$key]['taxableAmount'] = bcadd($vatGroups[$key]['taxableAmount'], $line->getLineTotal(), 2);
            $vatGroups[$key]['taxAmount'] = bcadd($vatGroups[$key]['taxAmount'], $line->getVatAmount(), 2);
        }

        // Adjust VAT groups with document-level allowance/charge contributions
        if (!empty($extensions['allowanceCharges'])) {
            foreach ($extensions['allowanceCharges'] as $ac) {
                $catCode = $ac['taxCategoryCode'] ?? 'S';
                $rate = $ac['taxRate'] ?? '0.00';
                $normalizedCode = $this->normalizeVatCategoryCode($catCode, $rate);
                $key = $normalizedCode . '_' . $rate;

                if (!isset($vatGroups[$key])) {
                    $vatGroups[$key] = [
                        'categoryCode' => $normalizedCode,
                        'rate' => $rate,
                        'taxableAmount' => '0.00',
                        'taxAmount' => '0.00',
                    ];
                }

                $amount = $ac['amount'];
                $vatOnAc = bcmul($amount, bcdiv($rate, '100', 6), 2);

                if ($ac['chargeIndicator']) {
                    // Charge increases taxable amount
                    $vatGroups[$key]['taxableAmount'] = bcadd($vatGroups[$key]['taxableAmount'], $amount, 2);
                    $vatGroups[$key]['taxAmount'] = bcadd($vatGroups[$key]['taxAmount'], $vatOnAc, 2);
                } else {
                    // Allowance decreases taxable amount
                    $vatGroups[$key]['taxableAmount'] = bcsub($vatGroups[$key]['taxableAmount'], $amount, 2);
                    $vatGroups[$key]['taxAmount'] = bcsub($vatGroups[$key]['taxAmount'], $vatOnAc, 2);
                }
            }
        }

        // Calculate total tax amount
        $totalTaxAmount = '0.00';
        foreach ($vatGroups as $group) {
            $totalTaxAmount = bcadd($totalTaxAmount, $group['taxAmount'], 2);
        }

        $taxTotal = $dom->createElement('cac:TaxTotal');
        $taxAmountEl = $dom->createElement('cbc:TaxAmount', $totalTaxAmount);
        $taxAmountEl->setAttribute('currencyID', $invoice->getCurrency());
        $taxTotal->appendChild($taxAmountEl);

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
            if (!$this->shouldOmitPercent($group['categoryCode'])) {
                $this->addElement($dom, $taxCat, 'cbc:Percent', $group['rate']);
            }

            // [BR-E-10, BR-AE-10, BR-O-10, BR-IC-10, BR-G-10] VAT exemption reason
            // [BR-S-10, BR-Z-10] Must NOT have exemption reason for S and Z categories
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

    /**
     * [BR-RO-030] Second TaxTotal in RON when invoice currency is not RON.
     * [BR-53] When TaxCurrencyCode is present, Invoice total VAT amount in accounting currency must be provided.
     */
    private function addTaxTotalInRon(\DOMDocument $dom, \DOMElement $root, Invoice $invoice, array $extensions = []): void
    {
        $rate = $invoice->getExchangeRate()
            ? (float) $invoice->getExchangeRate()
            : $this->exchangeRateService->getRate($invoice->getCurrency());

        if ($rate === null) {
            throw new \RuntimeException(
                sprintf('Exchange rate for %s is not available. Required by BR-RO-030 for non-RON invoices.', $invoice->getCurrency())
            );
        }

        // Compute adjusted VAT total (same logic as addTaxTotal)
        $adjustedVat = $invoice->getVatTotal();
        if (!empty($extensions['allowanceCharges'])) {
            foreach ($extensions['allowanceCharges'] as $ac) {
                $acRate = $ac['taxRate'] ?? '0.00';
                $vatOnAc = bcmul($ac['amount'], bcdiv($acRate, '100', 6), 2);
                if ($ac['chargeIndicator']) {
                    $adjustedVat = bcadd($adjustedVat, $vatOnAc, 2);
                } else {
                    $adjustedVat = bcsub($adjustedVat, $vatOnAc, 2);
                }
            }
        }

        $vatInRon = bcmul($adjustedVat, (string) $rate, 2);
        $taxTotalRon = $dom->createElement('cac:TaxTotal');
        $taxAmountRon = $dom->createElement('cbc:TaxAmount', $vatInRon);
        $taxAmountRon->setAttribute('currencyID', 'RON');
        $taxTotalRon->appendChild($taxAmountRon);
        $root->appendChild($taxTotalRon);
    }

    private function addLegalMonetaryTotal(
        \DOMDocument $dom,
        \DOMElement $root,
        Invoice $invoice,
        string $allowanceTotal = '0.00',
        string $chargeTotal = '0.00',
        string $prepaidAmount = '0.00',
    ): void {
        $currency = $invoice->getCurrency();
        $lineExtensionAmount = $invoice->getSubtotal();

        // TaxExclusiveAmount = LineExtensionAmount - allowances + charges
        $taxExclusiveAmount = bcsub(bcadd($lineExtensionAmount, $chargeTotal, 2), $allowanceTotal, 2);

        // Compute adjusted VAT for TaxInclusiveAmount
        $adjustedVat = $invoice->getVatTotal();
        $extensions = $invoice->getUblExtensions() ?? [];
        if (!empty($extensions['allowanceCharges'])) {
            foreach ($extensions['allowanceCharges'] as $ac) {
                $rate = $ac['taxRate'] ?? '0.00';
                $vatOnAc = bcmul($ac['amount'], bcdiv($rate, '100', 6), 2);
                if ($ac['chargeIndicator']) {
                    $adjustedVat = bcadd($adjustedVat, $vatOnAc, 2);
                } else {
                    $adjustedVat = bcsub($adjustedVat, $vatOnAc, 2);
                }
            }
        }

        // TaxInclusiveAmount = TaxExclusiveAmount + adjustedVat
        $taxInclusiveAmount = bcadd($taxExclusiveAmount, $adjustedVat, 2);

        // PayableAmount = TaxInclusiveAmount - prepaidAmount
        $payableAmount = bcsub($taxInclusiveAmount, $prepaidAmount, 2);

        $legalTotal = $dom->createElement('cac:LegalMonetaryTotal');

        $lineExtEl = $dom->createElement('cbc:LineExtensionAmount', $lineExtensionAmount);
        $lineExtEl->setAttribute('currencyID', $currency);
        $legalTotal->appendChild($lineExtEl);

        $taxExclEl = $dom->createElement('cbc:TaxExclusiveAmount', $taxExclusiveAmount);
        $taxExclEl->setAttribute('currencyID', $currency);
        $legalTotal->appendChild($taxExclEl);

        $taxInclEl = $dom->createElement('cbc:TaxInclusiveAmount', $taxInclusiveAmount);
        $taxInclEl->setAttribute('currencyID', $currency);
        $legalTotal->appendChild($taxInclEl);

        // AllowanceTotalAmount (when non-zero)
        if (bccomp($allowanceTotal, '0.00', 2) !== 0) {
            $allowanceEl = $dom->createElement('cbc:AllowanceTotalAmount', $allowanceTotal);
            $allowanceEl->setAttribute('currencyID', $currency);
            $legalTotal->appendChild($allowanceEl);
        }

        // ChargeTotalAmount (when non-zero)
        if (bccomp($chargeTotal, '0.00', 2) !== 0) {
            $chargeEl = $dom->createElement('cbc:ChargeTotalAmount', $chargeTotal);
            $chargeEl->setAttribute('currencyID', $currency);
            $legalTotal->appendChild($chargeEl);
        }

        // PrepaidAmount (when non-zero)
        if (bccomp($prepaidAmount, '0.00', 2) !== 0) {
            $prepaidEl = $dom->createElement('cbc:PrepaidAmount', $prepaidAmount);
            $prepaidEl->setAttribute('currencyID', $currency);
            $legalTotal->appendChild($prepaidEl);
        }

        $payableEl = $dom->createElement('cbc:PayableAmount', $payableAmount);
        $payableEl->setAttribute('currencyID', $currency);
        $legalTotal->appendChild($payableEl);

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
        // CreditNoteLine vs InvoiceLine
        $lineTag = $isCreditNote ? 'cac:CreditNoteLine' : 'cac:InvoiceLine';
        $invoiceLine = $dom->createElement($lineTag);

        $this->addElement($dom, $invoiceLine, 'cbc:ID', (string) $index);

        // CreditedQuantity vs InvoicedQuantity
        $qtyTag = $isCreditNote ? 'cbc:CreditedQuantity' : 'cbc:InvoicedQuantity';
        $quantity = $dom->createElement($qtyTag, $line->getQuantity());
        $quantity->setAttribute('unitCode', $this->mapUnitOfMeasure($line->getUnitOfMeasure()));
        $invoiceLine->appendChild($quantity);

        $lineAmount = $dom->createElement('cbc:LineExtensionAmount', $line->getLineTotal());
        $lineAmount->setAttribute('currencyID', $currency);
        $invoiceLine->appendChild($lineAmount);

        // BT-133 Buyer accounting reference (line level)
        if ($line->getBuyerAccountingRef()) {
            $this->addElement($dom, $invoiceLine, 'cbc:AccountingCost', $line->getBuyerAccountingRef());
        }

        // BT-127 Invoice line note
        if ($line->getLineNote()) {
            $this->addElement($dom, $invoiceLine, 'cbc:Note', $line->getLineNote());
        }

        // Line-level ublExtensions
        $lineExt = $line->getUblExtensions() ?? [];

        // InvoicePeriod on line (after AccountingCost/Note, before AllowanceCharge)
        if (!empty($lineExt['invoicePeriod'])) {
            $this->addInvoicePeriod($dom, $invoiceLine, $lineExt['invoicePeriod']);
        }

        // AllowanceCharge on line (after InvoicePeriod, before Item)
        if (!empty($lineExt['allowanceCharges'])) {
            foreach ($lineExt['allowanceCharges'] as $ac) {
                $acEl = $dom->createElement('cac:AllowanceCharge');
                $this->addElement($dom, $acEl, 'cbc:ChargeIndicator', $ac['chargeIndicator'] ? 'true' : 'false');
                if (!empty($ac['reasonCode'])) {
                    $this->addElement($dom, $acEl, 'cbc:AllowanceChargeReasonCode', $ac['reasonCode']);
                }
                if (!empty($ac['reason'])) {
                    $this->addElement($dom, $acEl, 'cbc:AllowanceChargeReason', $ac['reason']);
                }
                if (!empty($ac['multiplierFactorNumeric'])) {
                    $this->addElement($dom, $acEl, 'cbc:MultiplierFactorNumeric', $ac['multiplierFactorNumeric']);
                }
                $acAmountEl = $dom->createElement('cbc:Amount', $ac['amount']);
                $acAmountEl->setAttribute('currencyID', $currency);
                $acEl->appendChild($acAmountEl);
                if (!empty($ac['baseAmount'])) {
                    $baseEl = $dom->createElement('cbc:BaseAmount', $ac['baseAmount']);
                    $baseEl->setAttribute('currencyID', $currency);
                    $acEl->appendChild($baseEl);
                }
                $invoiceLine->appendChild($acEl);
            }
        }

        // Item [BR-RO-L1024 name max 100 chars]
        $item = $dom->createElement('cac:Item');
        $this->addElement($dom, $item, 'cbc:Name', $line->getDescription() ?? '');

        // BT-156 Buyer's item identification
        if ($line->getBuyerItemIdentification()) {
            $buyersItemId = $dom->createElement('cac:BuyersItemIdentification');
            $this->addElement($dom, $buyersItemId, 'cbc:ID', $line->getBuyerItemIdentification());
            $item->appendChild($buyersItemId);
        }

        // BT-157 Standard item identification (e.g. EAN)
        if ($line->getStandardItemIdentification()) {
            $stdItemId = $dom->createElement('cac:StandardItemIdentification');
            $stdId = $dom->createElement('cbc:ID', htmlspecialchars($line->getStandardItemIdentification(), ENT_XML1, 'UTF-8'));
            $stdId->setAttribute('schemeID', '0160');
            $stdItemId->appendChild($stdId);
            $item->appendChild($stdItemId);
        }

        // BT-158 Item classification identifier (CPV code)
        if ($line->getCpvCode()) {
            $commodityClass = $dom->createElement('cac:CommodityClassification');
            $classCode = $dom->createElement('cbc:ItemClassificationCode', htmlspecialchars($line->getCpvCode(), ENT_XML1, 'UTF-8'));
            $classCode->setAttribute('listID', 'CPV');
            $commodityClass->appendChild($classCode);
            $item->appendChild($commodityClass);
        }

        // Classified tax category
        $normalizedCode = $this->normalizeVatCategoryCode($line->getVatCategoryCode(), $line->getVatRate());
        $taxCategory = $dom->createElement('cac:ClassifiedTaxCategory');
        $this->addElement($dom, $taxCategory, 'cbc:ID', $normalizedCode);
        if (!$this->shouldOmitPercent($normalizedCode)) {
            $this->addElement($dom, $taxCategory, 'cbc:Percent', $line->getVatRate());
        }
        $lineTaxScheme = $dom->createElement('cac:TaxScheme');
        $this->addElement($dom, $lineTaxScheme, 'cbc:ID', 'VAT');
        $taxCategory->appendChild($lineTaxScheme);
        $item->appendChild($taxCategory);

        // AdditionalItemProperty from line ublExtensions (after ClassifiedTaxCategory)
        if (!empty($lineExt['additionalItemProperties'])) {
            foreach ($lineExt['additionalItemProperties'] as $prop) {
                $propEl = $dom->createElement('cac:AdditionalItemProperty');
                $this->addElement($dom, $propEl, 'cbc:Name', $prop['name']);
                $this->addElement($dom, $propEl, 'cbc:Value', $prop['value']);
                $item->appendChild($propEl);
            }
        }

        // OriginCountry from line ublExtensions
        if (!empty($lineExt['originCountry'])) {
            $originCountryEl = $dom->createElement('cac:OriginCountry');
            $this->addElement($dom, $originCountryEl, 'cbc:IdentificationCode', $lineExt['originCountry']);
            $item->appendChild($originCountryEl);
        }

        $invoiceLine->appendChild($item);

        // Price
        $price = $dom->createElement('cac:Price');
        $priceAmount = $dom->createElement('cbc:PriceAmount', $line->getUnitPrice());
        $priceAmount->setAttribute('currencyID', $currency);
        $price->appendChild($priceAmount);
        $invoiceLine->appendChild($price);

        $root->appendChild($invoiceLine);
    }

    private function addInvoicePeriod(\DOMDocument $dom, \DOMElement $parent, array $period): void
    {
        $periodEl = $dom->createElement('cac:InvoicePeriod');
        if (!empty($period['startDate'])) {
            $this->addElement($dom, $periodEl, 'cbc:StartDate', $period['startDate']);
        }
        if (!empty($period['endDate'])) {
            $this->addElement($dom, $periodEl, 'cbc:EndDate', $period['endDate']);
        }
        if (!empty($period['descriptionCode'])) {
            $this->addElement($dom, $periodEl, 'cbc:DescriptionCode', $period['descriptionCode']);
        }
        $parent->appendChild($periodEl);
    }

    private function addDeliveryElement(\DOMDocument $dom, \DOMElement $root, array $delivery): void
    {
        $deliveryEl = $dom->createElement('cac:Delivery');

        if (!empty($delivery['actualDeliveryDate'])) {
            $this->addElement($dom, $deliveryEl, 'cbc:ActualDeliveryDate', $delivery['actualDeliveryDate']);
        }

        if (!empty($delivery['deliveryAddress'])) {
            $addr = $delivery['deliveryAddress'];
            $locationEl = $dom->createElement('cac:DeliveryLocation');
            $addressEl = $dom->createElement('cac:Address');

            if (!empty($addr['streetName'])) {
                $this->addElement($dom, $addressEl, 'cbc:StreetName', $addr['streetName']);
            }
            if (!empty($addr['cityName'])) {
                $this->addElement($dom, $addressEl, 'cbc:CityName', $addr['cityName']);
            }
            if (!empty($addr['postalZone'])) {
                $this->addElement($dom, $addressEl, 'cbc:PostalZone', $addr['postalZone']);
            }
            if (!empty($addr['countrySubentity'])) {
                $this->addElement($dom, $addressEl, 'cbc:CountrySubentity', $addr['countrySubentity']);
            }
            if (!empty($addr['countryCode'])) {
                $countryEl = $dom->createElement('cac:Country');
                $this->addElement($dom, $countryEl, 'cbc:IdentificationCode', $addr['countryCode']);
                $addressEl->appendChild($countryEl);
            }

            $locationEl->appendChild($addressEl);
            $deliveryEl->appendChild($locationEl);
        }

        $root->appendChild($deliveryEl);
    }

    /**
     * @return array{0: string, 1: string} [allowanceTotal, chargeTotal]
     */
    private function addDocumentAllowanceCharges(\DOMDocument $dom, \DOMElement $root, array $allowanceCharges, string $currency): array
    {
        $allowanceTotal = '0.00';
        $chargeTotal = '0.00';

        foreach ($allowanceCharges as $ac) {
            $acEl = $dom->createElement('cac:AllowanceCharge');
            $this->addElement($dom, $acEl, 'cbc:ChargeIndicator', $ac['chargeIndicator'] ? 'true' : 'false');

            if (!empty($ac['reasonCode'])) {
                $this->addElement($dom, $acEl, 'cbc:AllowanceChargeReasonCode', $ac['reasonCode']);
            }
            if (!empty($ac['reason'])) {
                $this->addElement($dom, $acEl, 'cbc:AllowanceChargeReason', $ac['reason']);
            }
            // BR-31/BR-36: At least one of AllowanceChargeReason or AllowanceChargeReasonCode is required
            if (empty($ac['reasonCode']) && empty($ac['reason'])) {
                $this->addElement($dom, $acEl, 'cbc:AllowanceChargeReason',
                    $ac['chargeIndicator'] ? 'Charge' : 'Discount');
            }
            if (!empty($ac['multiplierFactorNumeric'])) {
                $this->addElement($dom, $acEl, 'cbc:MultiplierFactorNumeric', $ac['multiplierFactorNumeric']);
            }

            $amountEl = $dom->createElement('cbc:Amount', $ac['amount']);
            $amountEl->setAttribute('currencyID', $currency);
            $acEl->appendChild($amountEl);

            if (!empty($ac['baseAmount'])) {
                $baseEl = $dom->createElement('cbc:BaseAmount', $ac['baseAmount']);
                $baseEl->setAttribute('currencyID', $currency);
                $acEl->appendChild($baseEl);
            }

            // TaxCategory for document-level allowance/charge
            $taxCat = $dom->createElement('cac:TaxCategory');
            $catCode = $ac['taxCategoryCode'] ?? 'S';
            $this->addElement($dom, $taxCat, 'cbc:ID', $catCode);
            if (!$this->shouldOmitPercent($catCode)) {
                $this->addElement($dom, $taxCat, 'cbc:Percent', $ac['taxRate'] ?? '0.00');
            }
            // [BR-E-10, BR-AE-10, BR-O-10, BR-IC-10, BR-G-10] TaxExemptionReason in BG-20/BG-21 TaxCategory
            $exemptionReason = $this->getVatExemptionReason($catCode);
            if ($exemptionReason !== null) {
                $this->addElement($dom, $taxCat, 'cbc:TaxExemptionReason', $exemptionReason);
            }
            $taxScheme = $dom->createElement('cac:TaxScheme');
            $this->addElement($dom, $taxScheme, 'cbc:ID', 'VAT');
            $taxCat->appendChild($taxScheme);
            $acEl->appendChild($taxCat);

            $root->appendChild($acEl);

            if ($ac['chargeIndicator']) {
                $chargeTotal = bcadd($chargeTotal, $ac['amount'], 2);
            } else {
                $allowanceTotal = bcadd($allowanceTotal, $ac['amount'], 2);
            }
        }

        return [$allowanceTotal, $chargeTotal];
    }

    private function addElement(\DOMDocument $dom, \DOMElement $parent, string $name, string $value): void
    {
        $element = $dom->createElement($name, htmlspecialchars($value, ENT_XML1, 'UTF-8'));
        $parent->appendChild($element);
    }

    /**
     * Map Romanian unit-of-measure abbreviations to UN/ECE Recommendation 20 codes.
     */
    private function mapUnitOfMeasure(string $unit): string
    {
        return match (mb_strtolower($unit)) {
            'buc', 'bucata', 'bucati' => 'H87', // Piece
            'kg', 'kilogram' => 'KGM',
            'l', 'litru', 'litri' => 'LTR',
            'm', 'metru', 'metri' => 'MTR',
            'ora', 'ore', 'h' => 'HUR',
            'zi', 'zile' => 'DAY',
            'luna', 'luni' => 'MON',
            'set' => 'SET',
            'pachet' => 'PK',
            default => 'H87', // Default to piece
        };
    }

    /**
     * Reverse-map UNECE unit code to short Romanian label.
     * Used when importing from e-Factura XML to store user-friendly values.
     */
    public static function reverseMapUnitOfMeasure(string $uneceCode): string
    {
        return match (strtoupper($uneceCode)) {
            'H87', 'C62' => 'buc',
            'KGM' => 'kg',
            'LTR' => 'l',
            'MTR' => 'm',
            'HUR' => 'ora',
            'DAY' => 'zi',
            'MON' => 'luna',
            'SET' => 'set',
            'PK' => 'pachet',
            default => 'buc',
        };
    }

    /**
     * Map payment method strings to UNTDID 4461 payment means codes.
     */
    private function mapPaymentMethod(?string $method): string
    {
        return match ($method) {
            'cash' => '10',
            'cheque' => '20',
            'bank_transfer' => '30',
            'card' => '48',
            'other' => 'ZZZ',
            default => '30', // Credit transfer
        };
    }

    /**
     * Normalize VAT category code based on EN16931 schematron rules:
     *   BR-S-05:  S (Standard)       → Percent > 0
     *   BR-Z-05:  Z (Zero rate)      → Percent = 0
     *   BR-E-05:  E (Exempt)         → Percent = 0
     *   BR-AE-05: AE (Reverse charge)→ Percent = 0
     *   BR-IC-05: K (Intra-community)→ Percent = 0
     *   BR-G-05:  G (Export)         → Percent = 0
     *   BR-O-05:  O (Not subject)    → Percent must NOT exist
     *   BR-IG-05: L (Canary Islands) → Percent >= 0
     *   BR-IP-05: M (Tax for prod.)  → Percent >= 0
     */
    private function normalizeVatCategoryCode(string $categoryCode, string $vatRate): string
    {
        $rate = (float) $vatRate;

        // S (Standard) with 0% → should be Z (Zero rate)
        if ($categoryCode === 'S' && $rate === 0.0) {
            return 'Z';
        }

        // These codes require Percent = 0; if rate > 0, they're likely meant to be S
        if (in_array($categoryCode, ['Z', 'E', 'AE', 'K', 'G', 'O'], true) && $rate > 0.0) {
            return 'S';
        }

        return $categoryCode;
    }

    /**
     * [BR-O-05/06/07] Category O must NOT have a Percent element.
     */
    private function shouldOmitPercent(string $categoryCode): bool
    {
        return $categoryCode === 'O';
    }

    /**
     * [BR-O-02, BR-O-03] Returns true when every invoice line normalizes to VAT category O.
     * When this is the case, BT-31 (seller VAT ID) and BT-48 (buyer VAT ID) must be omitted.
     */
    private function allLinesAreCategoryO(Invoice $invoice): bool
    {
        $lines = $invoice->getLines();
        if ($lines->count() === 0) {
            return false;
        }
        foreach ($lines as $line) {
            $code = $this->normalizeVatCategoryCode($line->getVatCategoryCode(), $line->getVatRate());
            if ($code !== 'O') {
                return false;
            }
        }
        return true;
    }

    /**
     * [BR-E-10, BR-AE-10, BR-O-10, BR-IC-10, BR-G-10] VAT exemption reason text.
     * [BR-S-10, BR-Z-10, BR-IG-10, BR-IP-10] Must NOT have exemption reason.
     *
     * Returns null for categories that must not have an exemption reason.
     */
    private function getVatExemptionReason(string $categoryCode): ?string
    {
        return match ($categoryCode) {
            'E' => 'Scutit de TVA',
            'AE' => 'Taxare inversa',
            'K' => 'Livrare intracomunitara',
            'G' => 'Export in afara UE',
            'O' => 'Nu se supune TVA',
            default => null, // S, Z, L, M must NOT have exemption reason
        };
    }
}
