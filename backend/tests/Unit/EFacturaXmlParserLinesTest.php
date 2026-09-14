<?php

namespace App\Tests\Unit;

use App\Service\Anaf\EFacturaXmlParser;
use PHPUnit\Framework\TestCase;

class EFacturaXmlParserLinesTest extends TestCase
{
    private EFacturaXmlParser $parser;

    protected function setUp(): void
    {
        $this->parser = new EFacturaXmlParser();
    }

    /** @param array<int, array{net: string, item?: string}> $lines */
    private function xml(array $lines, string $declaredVat21, string $rate = '21.00'): string
    {
        $lineXml = '';
        foreach ($lines as $i => $l) {
            $n = $i + 1;
            $item = $l['item'] ?? '';
            $lineXml .= <<<L
    <cac:InvoiceLine>
        <cbc:ID>$n</cbc:ID>
        <cbc:InvoicedQuantity unitCode="H87">1</cbc:InvoicedQuantity>
        <cbc:LineExtensionAmount currencyID="RON">{$l['net']}</cbc:LineExtensionAmount>
        <cac:Item>
            <cbc:Name>Produs $n</cbc:Name>
            $item
            <cac:ClassifiedTaxCategory><cbc:ID>S</cbc:ID><cbc:Percent>$rate</cbc:Percent><cac:TaxScheme><cbc:ID>VAT</cbc:ID></cac:TaxScheme></cac:ClassifiedTaxCategory>
        </cac:Item>
        <cac:Price><cbc:PriceAmount currencyID="RON">{$l['net']}</cbc:PriceAmount></cac:Price>
    </cac:InvoiceLine>

L;
        }
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"
         xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2"
         xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">
    <cbc:ID>F-1</cbc:ID>
    <cbc:IssueDate>2026-09-01</cbc:IssueDate>
    <cbc:InvoiceTypeCode>380</cbc:InvoiceTypeCode>
    <cbc:DocumentCurrencyCode>RON</cbc:DocumentCurrencyCode>
    <cac:AccountingSupplierParty><cac:Party><cac:PartyLegalEntity><cbc:RegistrationName>Seller</cbc:RegistrationName><cbc:CompanyID>12345678</cbc:CompanyID></cac:PartyLegalEntity></cac:Party></cac:AccountingSupplierParty>
    <cac:AccountingCustomerParty><cac:Party><cac:PartyLegalEntity><cbc:RegistrationName>Buyer</cbc:RegistrationName><cbc:CompanyID>87654321</cbc:CompanyID></cac:PartyLegalEntity></cac:Party></cac:AccountingCustomerParty>
    <cac:TaxTotal>
        <cbc:TaxAmount currencyID="RON">$declaredVat21</cbc:TaxAmount>
        <cac:TaxSubtotal>
            <cbc:TaxableAmount currencyID="RON">0</cbc:TaxableAmount>
            <cbc:TaxAmount currencyID="RON">$declaredVat21</cbc:TaxAmount>
            <cac:TaxCategory><cbc:ID>S</cbc:ID><cbc:Percent>$rate</cbc:Percent><cac:TaxScheme><cbc:ID>VAT</cbc:ID></cac:TaxScheme></cac:TaxCategory>
        </cac:TaxSubtotal>
    </cac:TaxTotal>
    <cac:LegalMonetaryTotal><cbc:TaxExclusiveAmount currencyID="RON">0</cbc:TaxExclusiveAmount><cbc:PayableAmount currencyID="RON">0</cbc:PayableAmount></cac:LegalMonetaryTotal>
$lineXml
</Invoice>
XML;
    }

    public function testRoundingDifferenceGoesToTheLargestLine(): void
    {
        // Lines compute 21.00 + 10.50 + 2.10 = 33.60; the issuer declared 33.58 (rounded per unit)
        $parsed = $this->parser->parse($this->xml([['net' => '100.00'], ['net' => '50.00'], ['net' => '10.00']], '33.58'));

        self::assertSame('20.98', $parsed->lines[0]->vatAmount);
        self::assertSame('10.50', $parsed->lines[1]->vatAmount);
        self::assertSame('2.10', $parsed->lines[2]->vatAmount);
        self::assertSame('33.58', $parsed->vatTotal);
    }

    public function testDifferenceAtOrAboveToleranceIsLeftAlone(): void
    {
        $parsed = $this->parser->parse($this->xml([['net' => '100.00'], ['net' => '50.00']], '28.50'));

        self::assertSame('21.00', $parsed->lines[0]->vatAmount);
        self::assertSame('10.50', $parsed->lines[1]->vatAmount);
    }

    public function testExactMatchIsUntouched(): void
    {
        $parsed = $this->parser->parse($this->xml([['net' => '100.00'], ['net' => '50.00']], '31.50'));

        self::assertSame('21.00', $parsed->lines[0]->vatAmount);
        self::assertSame('10.50', $parsed->lines[1]->vatAmount);
    }

    public function testItemIdentifiersAreParsedAndKeptOnTheLine(): void
    {
        $item = '<cac:BuyersItemIdentification><cbc:ID>OUR-77</cbc:ID></cac:BuyersItemIdentification>'
            . '<cac:SellersItemIdentification><cbc:ID> SUP-123 </cbc:ID></cac:SellersItemIdentification>'
            . '<cac:StandardItemIdentification><cbc:ID schemeID="0160">5941234567890</cbc:ID></cac:StandardItemIdentification>';
        $parsed = $this->parser->parse($this->xml([['net' => '10.00', 'item' => $item], ['net' => '5.00']], '3.15'));

        $line = $parsed->lines[0];
        self::assertSame('5941234567890', $line->barcode);
        self::assertSame('SUP-123', $line->sellerItemCode);
        self::assertSame('OUR-77', $line->buyerItemCode);

        self::assertNull($parsed->lines[1]->barcode);
        self::assertNull($parsed->lines[1]->sellerItemCode);
    }
}
