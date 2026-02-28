<?php

namespace App\Service\EInvoice\France;

/**
 * Parses Factur-X CII (Cross-Industry Invoice) XML documents.
 */
class FacturXParser
{
    /**
     * Parse a Factur-X CII XML string into a structured array.
     */
    public function parse(string $xml): array
    {
        $dom = new \DOMDocument();
        if (!$dom->loadXML($xml)) {
            throw new \RuntimeException('Failed to parse Factur-X CII XML.');
        }

        $root = $dom->documentElement;

        return [
            'document' => $this->parseExchangedDocument($root),
            'seller' => $this->parseParty($root, 'SellerTradeParty'),
            'buyer' => $this->parseParty($root, 'BuyerTradeParty'),
            'lines' => $this->parseLines($root),
            'tax' => $this->parseTax($root),
            'totals' => $this->parseTotals($root),
        ];
    }

    private function parseExchangedDocument(\DOMElement $root): array
    {
        $doc = $this->getChildByLocalName($root, 'ExchangedDocument');
        if ($doc === null) {
            return [];
        }

        $issueDateTime = $this->getChildByLocalName($doc, 'IssueDateTime');
        $dateStr = $issueDateTime ? $this->getChildByLocalName($issueDateTime, 'DateTimeString') : null;

        return [
            'id' => $this->getTextByLocalName($doc, 'ID'),
            'typeCode' => $this->getTextByLocalName($doc, 'TypeCode'),
            'issueDate' => $dateStr?->textContent,
            'notes' => $this->getTextByLocalName($this->getChildByLocalName($doc, 'IncludedNote'), 'Content'),
        ];
    }

    private function parseParty(\DOMElement $root, string $partyName): array
    {
        $transaction = $this->getChildByLocalName($root, 'SupplyChainTradeTransaction');
        if ($transaction === null) {
            return [];
        }

        $agreement = $this->getChildByLocalName($transaction, 'ApplicableHeaderTradeAgreement');
        if ($agreement === null) {
            return [];
        }

        $party = $this->getChildByLocalName($agreement, $partyName);
        if ($party === null) {
            return [];
        }

        $postalAddress = $this->getChildByLocalName($party, 'PostalTradeAddress');
        $taxReg = $this->getChildByLocalName($party, 'SpecifiedTaxRegistration');

        return [
            'name' => $this->getTextByLocalName($party, 'Name'),
            'vatId' => $this->getTextByLocalName($taxReg, 'ID'),
            'address' => $this->getTextByLocalName($postalAddress, 'LineOne'),
            'city' => $this->getTextByLocalName($postalAddress, 'CityName'),
            'postalCode' => $this->getTextByLocalName($postalAddress, 'PostcodeCode'),
            'country' => $this->getTextByLocalName($postalAddress, 'CountryID'),
        ];
    }

    private function parseLines(\DOMElement $root): array
    {
        $transaction = $this->getChildByLocalName($root, 'SupplyChainTradeTransaction');
        if ($transaction === null) {
            return [];
        }

        $lines = [];
        foreach ($transaction->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->localName === 'IncludedSupplyChainTradeLineItem') {
                $lineDoc = $this->getChildByLocalName($child, 'AssociatedDocumentLineDocument');
                $product = $this->getChildByLocalName($child, 'SpecifiedTradeProduct');
                $lineAgreement = $this->getChildByLocalName($child, 'SpecifiedLineTradeAgreement');
                $lineDelivery = $this->getChildByLocalName($child, 'SpecifiedLineTradeDelivery');
                $lineSettlement = $this->getChildByLocalName($child, 'SpecifiedLineTradeSettlement');

                $netPrice = $lineAgreement ? $this->getChildByLocalName($lineAgreement, 'NetPriceProductTradePrice') : null;
                $summation = $lineSettlement ? $this->getChildByLocalName($lineSettlement, 'SpecifiedTradeSettlementLineMonetarySummation') : null;
                $tax = $lineSettlement ? $this->getChildByLocalName($lineSettlement, 'ApplicableTradeTax') : null;

                $lines[] = [
                    'lineId' => $this->getTextByLocalName($lineDoc, 'LineID'),
                    'name' => $this->getTextByLocalName($product, 'Name'),
                    'quantity' => $this->getTextByLocalName($lineDelivery, 'BilledQuantity'),
                    'unitPrice' => $this->getTextByLocalName($netPrice, 'ChargeAmount'),
                    'lineTotal' => $this->getTextByLocalName($summation, 'LineTotalAmount'),
                    'vatCategoryCode' => $this->getTextByLocalName($tax, 'CategoryCode'),
                    'vatRate' => $this->getTextByLocalName($tax, 'RateApplicablePercent'),
                ];
            }
        }

        return $lines;
    }

    private function parseTax(\DOMElement $root): array
    {
        $transaction = $this->getChildByLocalName($root, 'SupplyChainTradeTransaction');
        $settlement = $transaction ? $this->getChildByLocalName($transaction, 'ApplicableHeaderTradeSettlement') : null;
        if ($settlement === null) {
            return [];
        }

        $groups = [];
        foreach ($settlement->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->localName === 'ApplicableTradeTax') {
                $groups[] = [
                    'calculatedAmount' => $this->getTextByLocalName($child, 'CalculatedAmount'),
                    'basisAmount' => $this->getTextByLocalName($child, 'BasisAmount'),
                    'categoryCode' => $this->getTextByLocalName($child, 'CategoryCode'),
                    'ratePercent' => $this->getTextByLocalName($child, 'RateApplicablePercent'),
                ];
            }
        }

        return $groups;
    }

    private function parseTotals(\DOMElement $root): array
    {
        $transaction = $this->getChildByLocalName($root, 'SupplyChainTradeTransaction');
        $settlement = $transaction ? $this->getChildByLocalName($transaction, 'ApplicableHeaderTradeSettlement') : null;
        $summation = $settlement ? $this->getChildByLocalName($settlement, 'SpecifiedTradeSettlementHeaderMonetarySummation') : null;

        if ($summation === null) {
            return [];
        }

        return [
            'lineTotalAmount' => $this->getTextByLocalName($summation, 'LineTotalAmount'),
            'taxBasisTotalAmount' => $this->getTextByLocalName($summation, 'TaxBasisTotalAmount'),
            'taxTotalAmount' => $this->getTextByLocalName($summation, 'TaxTotalAmount'),
            'grandTotalAmount' => $this->getTextByLocalName($summation, 'GrandTotalAmount'),
            'duePayableAmount' => $this->getTextByLocalName($summation, 'DuePayableAmount'),
        ];
    }

    private function getChildByLocalName(?\DOMElement $parent, string $localName): ?\DOMElement
    {
        if ($parent === null) {
            return null;
        }

        foreach ($parent->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->localName === $localName) {
                return $child;
            }
        }

        return null;
    }

    private function getTextByLocalName(?\DOMElement $parent, string $localName): ?string
    {
        $child = $this->getChildByLocalName($parent, $localName);
        return $child?->textContent ?: null;
    }
}
