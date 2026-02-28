<?php

namespace App\Service\EInvoice\France;

use App\Entity\Invoice;
use App\Enum\DocumentType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Generates Factur-X CII (Cross-Industry Invoice) XML.
 *
 * Factur-X uses the UN/CEFACT CII format — completely different from UBL.
 * Root: <rsm:CrossIndustryInvoice>
 * Profile: EN 16931 (COMFORT) — full compliance with European standard.
 *
 * Note: The final Factur-X document is this XML embedded in a PDF/A-3.
 * PDF embedding is handled separately by the PDF generation pipeline.
 *
 * @see https://fnfe-mpe.org/factur-x/
 */
class FacturXGenerator
{
    private const NS_RSM = 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100';
    private const NS_RAM = 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100';
    private const NS_QDT = 'urn:un:unece:uncefact:data:standard:QualifiedDataType:100';
    private const NS_UDT = 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function generate(Invoice $invoice): string
    {
        $company = $invoice->getCompany();

        $filters = $this->entityManager->getFilters();
        $filterWasEnabled = $filters->isEnabled('soft_delete');
        if ($filterWasEnabled) {
            $filters->disable('soft_delete');
        }
        $client = $invoice->getClient();
        if ($filterWasEnabled) {
            $filters->enable('soft_delete');
        }

        if ($invoice->getDocumentType() === DocumentType::PROFORMA) {
            throw new \InvalidArgumentException('Proforma invoices cannot be submitted as Factur-X.');
        }

        $isCreditNote = $invoice->getDocumentType() === DocumentType::CREDIT_NOTE;

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        // Root element with CII namespaces
        $root = $dom->createElementNS(self::NS_RSM, 'rsm:CrossIndustryInvoice');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:ram', self::NS_RAM);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:qdt', self::NS_QDT);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:udt', self::NS_UDT);
        $dom->appendChild($root);

        // === ExchangedDocumentContext ===
        $context = $this->el($dom, 'rsm:ExchangedDocumentContext');
        $root->appendChild($context);

        $guidelineContext = $this->el($dom, 'ram:GuidelineSpecifiedDocumentContextParameter');
        $guidelineContext->appendChild($this->elVal($dom, 'ram:ID', 'urn:cen.eu:en16931:2017#compliant#urn:factur-x.eu:1p0:en16931'));
        $context->appendChild($guidelineContext);

        // === ExchangedDocument ===
        $exchangedDoc = $this->el($dom, 'rsm:ExchangedDocument');
        $root->appendChild($exchangedDoc);

        $exchangedDoc->appendChild($this->elVal($dom, 'ram:ID', $invoice->getNumber() ?? ''));
        $exchangedDoc->appendChild($this->elVal($dom, 'ram:TypeCode', $isCreditNote ? '381' : '380'));

        $issueDateTime = $this->el($dom, 'ram:IssueDateTime');
        $dateString = $this->elVal($dom, 'udt:DateTimeString', $invoice->getIssueDate()?->format('Ymd') ?? '');
        $dateString->setAttribute('format', '102'); // CCYYMMDD
        $issueDateTime->appendChild($dateString);
        $exchangedDoc->appendChild($issueDateTime);

        if ($invoice->getNotes()) {
            $note = $this->el($dom, 'ram:IncludedNote');
            $note->appendChild($this->elVal($dom, 'ram:Content', $invoice->getNotes()));
            $exchangedDoc->appendChild($note);
        }

        // === SupplyChainTradeTransaction ===
        $transaction = $this->el($dom, 'rsm:SupplyChainTradeTransaction');
        $root->appendChild($transaction);

        // Lines
        $lineNum = 1;
        foreach ($invoice->getLines() as $line) {
            $this->addLineItem($dom, $transaction, $line, $lineNum, $invoice->getCurrency());
            $lineNum++;
        }

        // HeaderTradeAgreement (parties)
        $agreement = $this->el($dom, 'ram:ApplicableHeaderTradeAgreement');
        $transaction->appendChild($agreement);

        // BuyerReference
        $buyerRef = $this->resolveBuyerReference($client);
        if ($buyerRef) {
            $agreement->appendChild($this->elVal($dom, 'ram:BuyerReference', $buyerRef));
        }

        $this->addSellerParty($dom, $agreement, $company);
        $this->addBuyerParty($dom, $agreement, $client);

        // OrderReference
        if ($invoice->getOrderNumber()) {
            $buyerOrder = $this->el($dom, 'ram:BuyerOrderReferencedDocument');
            $buyerOrder->appendChild($this->elVal($dom, 'ram:IssuerAssignedID', $invoice->getOrderNumber()));
            $agreement->appendChild($buyerOrder);
        }

        // ContractReference
        if ($invoice->getContractNumber()) {
            $contract = $this->el($dom, 'ram:ContractReferencedDocument');
            $contract->appendChild($this->elVal($dom, 'ram:IssuerAssignedID', $invoice->getContractNumber()));
            $agreement->appendChild($contract);
        }

        // HeaderTradeDelivery
        $delivery = $this->el($dom, 'ram:ApplicableHeaderTradeDelivery');
        $transaction->appendChild($delivery);

        // HeaderTradeSettlement (payment, tax, totals)
        $settlement = $this->el($dom, 'ram:ApplicableHeaderTradeSettlement');
        $transaction->appendChild($settlement);

        $settlement->appendChild($this->elVal($dom, 'ram:InvoiceCurrencyCode', $invoice->getCurrency()));

        // Payment means
        $this->addPaymentMeans($dom, $settlement, $invoice, $company);

        // Tax breakdown
        $this->addTaxBreakdown($dom, $settlement, $invoice);

        // PaymentTerms must come before MonetarySummation
        if (!$isCreditNote && $invoice->getDueDate() !== null) {
            $paymentTerms = $this->el($dom, 'ram:SpecifiedTradePaymentTerms');
            $dueDateEl = $this->el($dom, 'ram:DueDateDateTime');
            $dueDateStr = $this->elVal($dom, 'udt:DateTimeString', $invoice->getDueDate()->format('Ymd'));
            $dueDateStr->setAttribute('format', '102');
            $dueDateEl->appendChild($dueDateStr);
            $paymentTerms->appendChild($dueDateEl);
            $settlement->appendChild($paymentTerms);
        }

        // Monetary summation (must come after PaymentTerms)
        $this->addMonetarySummation($dom, $settlement, $invoice);

        // BillingReference for credit notes
        if ($isCreditNote && $invoice->getParentDocument() !== null) {
            $billingRef = $this->el($dom, 'ram:InvoiceReferencedDocument');
            $billingRef->appendChild($this->elVal($dom, 'ram:IssuerAssignedID', $invoice->getParentDocument()->getNumber() ?? ''));
            $settlement->appendChild($billingRef);
        }

        return $dom->saveXML();
    }

    private function addLineItem(\DOMDocument $dom, \DOMElement $transaction, $line, int $lineNum, string $currency): void
    {
        $lineItem = $this->el($dom, 'ram:IncludedSupplyChainTradeLineItem');

        // Line doc
        $lineDoc = $this->el($dom, 'ram:AssociatedDocumentLineDocument');
        $lineDoc->appendChild($this->elVal($dom, 'ram:LineID', (string) $lineNum));
        $lineItem->appendChild($lineDoc);

        // Product
        $product = $this->el($dom, 'ram:SpecifiedTradeProduct');
        $product->appendChild($this->elVal($dom, 'ram:Name', $line->getDescription() ?? ''));
        $lineItem->appendChild($product);

        // Line agreement (price)
        $lineAgreement = $this->el($dom, 'ram:SpecifiedLineTradeAgreement');
        $netPrice = $this->el($dom, 'ram:NetPriceProductTradePrice');
        $chargeAmount = $this->elVal($dom, 'ram:ChargeAmount', $line->getUnitPrice());
        $netPrice->appendChild($chargeAmount);
        $lineAgreement->appendChild($netPrice);
        $lineItem->appendChild($lineAgreement);

        // Line delivery (quantity)
        $lineDelivery = $this->el($dom, 'ram:SpecifiedLineTradeDelivery');
        $billedQty = $this->elVal($dom, 'ram:BilledQuantity', $line->getQuantity());
        $billedQty->setAttribute('unitCode', $this->mapUnitOfMeasure($line->getUnitOfMeasure()));
        $lineDelivery->appendChild($billedQty);
        $lineItem->appendChild($lineDelivery);

        // Line settlement (tax + total)
        $lineSettlement = $this->el($dom, 'ram:SpecifiedLineTradeSettlement');

        $tradeTax = $this->el($dom, 'ram:ApplicableTradeTax');
        $tradeTax->appendChild($this->elVal($dom, 'ram:TypeCode', 'VAT'));
        $tradeTax->appendChild($this->elVal($dom, 'ram:CategoryCode', $line->getVatCategoryCode()));
        $tradeTax->appendChild($this->elVal($dom, 'ram:RateApplicablePercent', $line->getVatRate()));
        $lineSettlement->appendChild($tradeTax);

        $lineTotal = $this->el($dom, 'ram:SpecifiedTradeSettlementLineMonetarySummation');
        $lineTotal->appendChild($this->elVal($dom, 'ram:LineTotalAmount', $line->getLineTotal()));
        $lineSettlement->appendChild($lineTotal);

        $lineItem->appendChild($lineSettlement);

        $transaction->appendChild($lineItem);
    }

    private function addSellerParty(\DOMDocument $dom, \DOMElement $agreement, $company): void
    {
        $sellerParty = $this->el($dom, 'ram:SellerTradeParty');

        $sellerParty->appendChild($this->elVal($dom, 'ram:Name', $company?->getName() ?? ''));

        // SpecifiedLegalOrganization (SIRET for French companies)
        if ($company?->getRegistrationNumber()) {
            $legalOrg = $this->el($dom, 'ram:SpecifiedLegalOrganization');
            $legalOrgId = $this->elVal($dom, 'ram:ID', $company->getRegistrationNumber());
            $legalOrgId->setAttribute('schemeID', '0002'); // SIRET
            $legalOrg->appendChild($legalOrgId);
            $sellerParty->appendChild($legalOrg);
        }

        // PostalTradeAddress
        $postalAddress = $this->el($dom, 'ram:PostalTradeAddress');
        $postalAddress->appendChild($this->elVal($dom, 'ram:LineOne', $company?->getAddress() ?? ''));
        $postalAddress->appendChild($this->elVal($dom, 'ram:CityName', $company?->getCity() ?? ''));
        $postalAddress->appendChild($this->elVal($dom, 'ram:CountryID', $company?->getCountry() ?? 'FR'));
        $sellerParty->appendChild($postalAddress);

        // URIUniversalCommunication (electronic address for routing)
        if ($company?->getEmail()) {
            $uriComm = $this->el($dom, 'ram:URIUniversalCommunication');
            $uriId = $this->elVal($dom, 'ram:URIID', $company->getEmail());
            $uriId->setAttribute('schemeID', 'EM');
            $uriComm->appendChild($uriId);
            $sellerParty->appendChild($uriComm);
        }

        // SpecifiedTaxRegistration must come last
        $taxReg = $this->el($dom, 'ram:SpecifiedTaxRegistration');
        $taxId = $this->elVal($dom, 'ram:ID', ($company?->getCountry() ?? 'FR') . $company?->getCif());
        $taxId->setAttribute('schemeID', 'VA'); // VAT
        $taxReg->appendChild($taxId);
        $sellerParty->appendChild($taxReg);

        $agreement->appendChild($sellerParty);
    }

    private function addBuyerParty(\DOMDocument $dom, \DOMElement $agreement, $client): void
    {
        $buyerParty = $this->el($dom, 'ram:BuyerTradeParty');

        $buyerParty->appendChild($this->elVal($dom, 'ram:Name', $client?->getName() ?? ''));

        if ($client !== null) {
            // PostalTradeAddress
            $postalAddress = $this->el($dom, 'ram:PostalTradeAddress');
            $postalAddress->appendChild($this->elVal($dom, 'ram:LineOne', $client->getAddress() ?? ''));
            $postalAddress->appendChild($this->elVal($dom, 'ram:CityName', $client->getCity() ?? ''));
            if ($client->getPostalCode()) {
                $postalAddress->appendChild($this->elVal($dom, 'ram:PostcodeCode', $client->getPostalCode()));
            }
            $postalAddress->appendChild($this->elVal($dom, 'ram:CountryID', $client->getCountry() ?? 'FR'));
            $buyerParty->appendChild($postalAddress);

            // URIUniversalCommunication
            if ($client->getEmail()) {
                $uriComm = $this->el($dom, 'ram:URIUniversalCommunication');
                $uriId = $this->elVal($dom, 'ram:URIID', $client->getEmail());
                $uriId->setAttribute('schemeID', 'EM');
                $uriComm->appendChild($uriId);
                $buyerParty->appendChild($uriComm);
            }

            // SpecifiedTaxRegistration must come last
            if ($client->getCui()) {
                $taxReg = $this->el($dom, 'ram:SpecifiedTaxRegistration');
                $countryPrefix = $client->getCountry() ?: 'FR';
                $taxId = $this->elVal($dom, 'ram:ID', $countryPrefix . $client->getCui());
                $taxId->setAttribute('schemeID', 'VA');
                $taxReg->appendChild($taxId);
                $buyerParty->appendChild($taxReg);
            }
        }

        $agreement->appendChild($buyerParty);
    }

    private function addPaymentMeans(\DOMDocument $dom, \DOMElement $settlement, Invoice $invoice, $company): void
    {
        $paymentMeans = $this->el($dom, 'ram:SpecifiedTradeSettlementPaymentMeans');
        $paymentMeans->appendChild($this->elVal($dom, 'ram:TypeCode', $this->mapPaymentMethod($invoice->getPaymentMethod())));

        if ($company?->getBankAccount()) {
            $account = $this->el($dom, 'ram:PayeePartyCreditorFinancialAccount');
            $account->appendChild($this->elVal($dom, 'ram:IBANID', str_replace(' ', '', $company->getBankAccount())));
            $paymentMeans->appendChild($account);

            if ($company->getBankBic()) {
                $institution = $this->el($dom, 'ram:PayeeSpecifiedCreditorFinancialInstitution');
                $institution->appendChild($this->elVal($dom, 'ram:BICID', $company->getBankBic()));
                $paymentMeans->appendChild($institution);
            }
        }

        $settlement->appendChild($paymentMeans);
    }

    private function addTaxBreakdown(\DOMDocument $dom, \DOMElement $settlement, Invoice $invoice): void
    {
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
            $tradeTax = $this->el($dom, 'ram:ApplicableTradeTax');
            $tradeTax->appendChild($this->elVal($dom, 'ram:CalculatedAmount', $group['taxAmount']));
            $tradeTax->appendChild($this->elVal($dom, 'ram:TypeCode', 'VAT'));
            $tradeTax->appendChild($this->elVal($dom, 'ram:BasisAmount', $group['taxableAmount']));
            $tradeTax->appendChild($this->elVal($dom, 'ram:CategoryCode', $group['categoryCode']));
            $tradeTax->appendChild($this->elVal($dom, 'ram:RateApplicablePercent', $group['rate']));

            $exemptionReason = $this->getVatExemptionReason($group['categoryCode']);
            if ($exemptionReason !== null) {
                $tradeTax->appendChild($this->elVal($dom, 'ram:ExemptionReason', $exemptionReason));
            }

            $settlement->appendChild($tradeTax);
        }
    }

    private function addMonetarySummation(\DOMDocument $dom, \DOMElement $settlement, Invoice $invoice): void
    {
        $summation = $this->el($dom, 'ram:SpecifiedTradeSettlementHeaderMonetarySummation');

        $summation->appendChild($this->elVal($dom, 'ram:LineTotalAmount', $invoice->getSubtotal()));
        $summation->appendChild($this->elVal($dom, 'ram:TaxBasisTotalAmount', $invoice->getSubtotal()));

        $taxTotal = $this->elVal($dom, 'ram:TaxTotalAmount', $invoice->getVatTotal());
        $taxTotal->setAttribute('currencyID', $invoice->getCurrency());
        $summation->appendChild($taxTotal);

        $summation->appendChild($this->elVal($dom, 'ram:GrandTotalAmount', $invoice->getTotal()));
        $summation->appendChild($this->elVal($dom, 'ram:DuePayableAmount', $invoice->getTotal()));

        $settlement->appendChild($summation);
    }

    private function resolveBuyerReference($client): ?string
    {
        if ($client === null) {
            return null;
        }

        $identifiers = $client->getEinvoiceIdentifier('facturx');
        if ($identifiers !== null && !empty($identifiers['serviceCode'])) {
            return $identifiers['serviceCode'];
        }

        return $client->getClientCode() ?? null;
    }

    private function el(\DOMDocument $dom, string $name): \DOMElement
    {
        return $dom->createElement($name);
    }

    private function elVal(\DOMDocument $dom, string $name, string $value): \DOMElement
    {
        return $dom->createElement($name, htmlspecialchars($value, ENT_XML1, 'UTF-8'));
    }

    private function mapUnitOfMeasure(string $unit): string
    {
        return match (mb_strtolower($unit)) {
            'buc', 'bucata', 'piece', 'pcs', 'unite' => 'H87',
            'kg', 'kilogram' => 'KGM',
            'l', 'litru', 'litre', 'liter' => 'LTR',
            'm', 'metru', 'metre', 'meter' => 'MTR',
            'ora', 'ore', 'h', 'heure', 'hour' => 'HUR',
            'zi', 'zile', 'jour', 'day' => 'DAY',
            'luna', 'luni', 'mois', 'month' => 'MON',
            'set', 'ensemble' => 'SET',
            'pachet', 'paquet', 'package' => 'PK',
            default => 'H87',
        };
    }

    private function mapPaymentMethod(?string $method): string
    {
        return match ($method) {
            'cash' => '10',
            'cheque' => '20',
            'bank_transfer' => '30',
            'card' => '48',
            'direct_debit' => '49',
            default => '30',
        };
    }

    private function getVatExemptionReason(string $categoryCode): ?string
    {
        return match ($categoryCode) {
            'E' => 'Exonere de TVA',
            'AE' => 'Autoliquidation',
            'K' => 'Livraison intracommunautaire',
            'G' => 'Exportation hors UE',
            'O' => 'Non soumis a la TVA',
            default => null,
        };
    }
}
