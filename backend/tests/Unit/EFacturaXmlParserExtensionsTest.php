<?php

namespace App\Tests\Unit;

use App\Service\Anaf\EFacturaXmlParser;
use PHPUnit\Framework\TestCase;

class EFacturaXmlParserExtensionsTest extends TestCase
{
    private EFacturaXmlParser $parser;

    protected function setUp(): void
    {
        $this->parser = new EFacturaXmlParser();
    }

    private function buildInvoiceXml(string $extraElements = '', string $lineExtra = ''): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"
         xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2"
         xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">
    <cbc:UBLVersionID>2.1</cbc:UBLVersionID>
    <cbc:CustomizationID>urn:cen.eu:en16931:2017</cbc:CustomizationID>
    <cbc:ID>TEST001</cbc:ID>
    <cbc:IssueDate>2026-03-01</cbc:IssueDate>
    <cbc:DueDate>2026-03-31</cbc:DueDate>
    <cbc:InvoiceTypeCode>380</cbc:InvoiceTypeCode>
    <cbc:DocumentCurrencyCode>RON</cbc:DocumentCurrencyCode>
    {$extraElements}
    <cac:AccountingSupplierParty>
        <cac:Party>
            <cac:PartyName><cbc:Name>Seller SRL</cbc:Name></cac:PartyName>
            <cac:PostalAddress>
                <cbc:StreetName>Str. Seller 1</cbc:StreetName>
                <cbc:CityName>Cluj</cbc:CityName>
                <cbc:CountrySubentity>RO-CJ</cbc:CountrySubentity>
                <cac:Country><cbc:IdentificationCode>RO</cbc:IdentificationCode></cac:Country>
            </cac:PostalAddress>
            <cac:PartyTaxScheme>
                <cbc:CompanyID>RO12345678</cbc:CompanyID>
                <cac:TaxScheme><cbc:ID>VAT</cbc:ID></cac:TaxScheme>
            </cac:PartyTaxScheme>
            <cac:PartyLegalEntity>
                <cbc:RegistrationName>Seller SRL</cbc:RegistrationName>
                <cbc:CompanyID>12345678</cbc:CompanyID>
            </cac:PartyLegalEntity>
        </cac:Party>
    </cac:AccountingSupplierParty>
    <cac:AccountingCustomerParty>
        <cac:Party>
            <cac:PartyName><cbc:Name>Buyer SRL</cbc:Name></cac:PartyName>
            <cac:PostalAddress>
                <cbc:StreetName>Str. Buyer 2</cbc:StreetName>
                <cbc:CityName>Bucuresti</cbc:CityName>
                <cbc:CountrySubentity>RO-B</cbc:CountrySubentity>
                <cac:Country><cbc:IdentificationCode>RO</cbc:IdentificationCode></cac:Country>
            </cac:PostalAddress>
            <cac:PartyTaxScheme>
                <cbc:CompanyID>RO87654321</cbc:CompanyID>
                <cac:TaxScheme><cbc:ID>VAT</cbc:ID></cac:TaxScheme>
            </cac:PartyTaxScheme>
            <cac:PartyLegalEntity>
                <cbc:RegistrationName>Buyer SRL</cbc:RegistrationName>
                <cbc:CompanyID>87654321</cbc:CompanyID>
            </cac:PartyLegalEntity>
        </cac:Party>
    </cac:AccountingCustomerParty>
    <cac:TaxTotal>
        <cbc:TaxAmount currencyID="RON">190.00</cbc:TaxAmount>
        <cac:TaxSubtotal>
            <cbc:TaxableAmount currencyID="RON">1000.00</cbc:TaxableAmount>
            <cbc:TaxAmount currencyID="RON">190.00</cbc:TaxAmount>
            <cac:TaxCategory>
                <cbc:ID>S</cbc:ID>
                <cbc:Percent>19.00</cbc:Percent>
                <cac:TaxScheme><cbc:ID>VAT</cbc:ID></cac:TaxScheme>
            </cac:TaxCategory>
        </cac:TaxSubtotal>
    </cac:TaxTotal>
    <cac:LegalMonetaryTotal>
        <cbc:LineExtensionAmount currencyID="RON">1000.00</cbc:LineExtensionAmount>
        <cbc:TaxExclusiveAmount currencyID="RON">1000.00</cbc:TaxExclusiveAmount>
        <cbc:TaxInclusiveAmount currencyID="RON">1190.00</cbc:TaxInclusiveAmount>
        <cbc:PayableAmount currencyID="RON">1190.00</cbc:PayableAmount>
    </cac:LegalMonetaryTotal>
    <cac:InvoiceLine>
        <cbc:ID>1</cbc:ID>
        <cbc:InvoicedQuantity unitCode="H87">1.0000</cbc:InvoicedQuantity>
        <cbc:LineExtensionAmount currencyID="RON">1000.00</cbc:LineExtensionAmount>
        {$lineExtra}
        <cac:Item>
            <cbc:Name>Service IT</cbc:Name>
            <cac:ClassifiedTaxCategory>
                <cbc:ID>S</cbc:ID>
                <cbc:Percent>19.00</cbc:Percent>
                <cac:TaxScheme><cbc:ID>VAT</cbc:ID></cac:TaxScheme>
            </cac:ClassifiedTaxCategory>
        </cac:Item>
        <cac:Price>
            <cbc:PriceAmount currencyID="RON">1000.00</cbc:PriceAmount>
        </cac:Price>
    </cac:InvoiceLine>
</Invoice>
XML;
    }

    // === InvoicePeriod ===

    public function testParsesInvoicePeriod(): void
    {
        $xml = $this->buildInvoiceXml(
            '<cac:InvoicePeriod>
                <cbc:StartDate>2026-03-01</cbc:StartDate>
                <cbc:EndDate>2026-03-31</cbc:EndDate>
                <cbc:DescriptionCode>35</cbc:DescriptionCode>
            </cac:InvoicePeriod>'
        );

        $result = $this->parser->parse($xml);

        $this->assertNotNull($result->ublExtensions);
        $this->assertArrayHasKey('invoicePeriod', $result->ublExtensions);
        $this->assertSame('2026-03-01', $result->ublExtensions['invoicePeriod']['startDate']);
        $this->assertSame('2026-03-31', $result->ublExtensions['invoicePeriod']['endDate']);
        $this->assertSame('35', $result->ublExtensions['invoicePeriod']['descriptionCode']);
    }

    // === Delivery ===

    public function testParsesDelivery(): void
    {
        $xml = $this->buildInvoiceXml(
            '<cac:Delivery>
                <cbc:ActualDeliveryDate>2026-03-15</cbc:ActualDeliveryDate>
                <cac:DeliveryLocation>
                    <cac:Address>
                        <cbc:StreetName>Str. Depozit 5</cbc:StreetName>
                        <cbc:CityName>Timisoara</cbc:CityName>
                        <cbc:CountrySubentity>RO-TM</cbc:CountrySubentity>
                        <cac:Country><cbc:IdentificationCode>RO</cbc:IdentificationCode></cac:Country>
                    </cac:Address>
                </cac:DeliveryLocation>
            </cac:Delivery>'
        );

        $result = $this->parser->parse($xml);

        $this->assertNotNull($result->ublExtensions);
        $delivery = $result->ublExtensions['delivery'];
        $this->assertSame('2026-03-15', $delivery['actualDeliveryDate']);
        $this->assertSame('Str. Depozit 5', $delivery['deliveryAddress']['streetName']);
        $this->assertSame('Timisoara', $delivery['deliveryAddress']['cityName']);
        $this->assertSame('RO', $delivery['deliveryAddress']['countryCode']);
    }

    // === AllowanceCharge ===

    public function testParsesDocumentAllowanceCharge(): void
    {
        $xml = $this->buildInvoiceXml(
            '<cac:AllowanceCharge>
                <cbc:ChargeIndicator>false</cbc:ChargeIndicator>
                <cbc:AllowanceChargeReasonCode>95</cbc:AllowanceChargeReasonCode>
                <cbc:AllowanceChargeReason>Discount 10%</cbc:AllowanceChargeReason>
                <cbc:MultiplierFactorNumeric>10.00</cbc:MultiplierFactorNumeric>
                <cbc:Amount currencyID="RON">100.00</cbc:Amount>
                <cbc:BaseAmount currencyID="RON">1000.00</cbc:BaseAmount>
                <cac:TaxCategory>
                    <cbc:ID>S</cbc:ID>
                    <cbc:Percent>19.00</cbc:Percent>
                    <cac:TaxScheme><cbc:ID>VAT</cbc:ID></cac:TaxScheme>
                </cac:TaxCategory>
            </cac:AllowanceCharge>'
        );

        $result = $this->parser->parse($xml);

        $this->assertNotNull($result->ublExtensions);
        $this->assertCount(1, $result->ublExtensions['allowanceCharges']);
        $ac = $result->ublExtensions['allowanceCharges'][0];
        $this->assertFalse($ac['chargeIndicator']);
        $this->assertSame('100.00', $ac['amount']);
        $this->assertSame('95', $ac['reasonCode']);
        $this->assertSame('Discount 10%', $ac['reason']);
        $this->assertSame('S', $ac['taxCategoryCode']);
        $this->assertSame('19.00', $ac['taxRate']);
        $this->assertSame('1000.00', $ac['baseAmount']);
    }

    public function testParsesChargeIndicatorTrue(): void
    {
        $xml = $this->buildInvoiceXml(
            '<cac:AllowanceCharge>
                <cbc:ChargeIndicator>true</cbc:ChargeIndicator>
                <cbc:Amount currencyID="RON">50.00</cbc:Amount>
                <cac:TaxCategory>
                    <cbc:ID>S</cbc:ID>
                    <cbc:Percent>19.00</cbc:Percent>
                    <cac:TaxScheme><cbc:ID>VAT</cbc:ID></cac:TaxScheme>
                </cac:TaxCategory>
            </cac:AllowanceCharge>'
        );

        $result = $this->parser->parse($xml);

        $ac = $result->ublExtensions['allowanceCharges'][0];
        $this->assertTrue($ac['chargeIndicator']);
        $this->assertSame('50.00', $ac['amount']);
    }

    // === PrepaidAmount ===

    public function testParsesPrepaidAmount(): void
    {
        // Replace LegalMonetaryTotal to include PrepaidAmount
        $xml = str_replace(
            '<cbc:PayableAmount currencyID="RON">1190.00</cbc:PayableAmount>',
            '<cbc:PrepaidAmount currencyID="RON">200.00</cbc:PrepaidAmount>
            <cbc:PayableAmount currencyID="RON">990.00</cbc:PayableAmount>',
            $this->buildInvoiceXml()
        );

        $result = $this->parser->parse($xml);

        $this->assertNotNull($result->ublExtensions);
        $this->assertSame('200.00', $result->ublExtensions['prepaidAmount']);
    }

    // === AdditionalDocumentReferences ===

    public function testParsesAdditionalDocumentReferences(): void
    {
        $xml = $this->buildInvoiceXml(
            '<cac:AdditionalDocumentReference>
                <cbc:ID>ATT-001</cbc:ID>
                <cbc:DocumentTypeCode>916</cbc:DocumentTypeCode>
                <cbc:DocumentDescription>Timesheet</cbc:DocumentDescription>
            </cac:AdditionalDocumentReference>'
        );

        $result = $this->parser->parse($xml);

        $this->assertNotNull($result->ublExtensions);
        $this->assertCount(1, $result->ublExtensions['additionalDocumentReferences']);
        $ref = $result->ublExtensions['additionalDocumentReferences'][0];
        $this->assertSame('ATT-001', $ref['id']);
        $this->assertSame('916', $ref['documentTypeCode']);
        $this->assertSame('Timesheet', $ref['documentDescription']);
    }

    public function testSkipsDocType130AsInvoicedObjectIdentifier(): void
    {
        $xml = $this->buildInvoiceXml(
            '<cac:AdditionalDocumentReference>
                <cbc:ID>INV-OBJ-001</cbc:ID>
                <cbc:DocumentTypeCode>130</cbc:DocumentTypeCode>
            </cac:AdditionalDocumentReference>'
        );

        $result = $this->parser->parse($xml);

        // Should not appear in ublExtensions since DocType=130 is the existing invoicedObjectIdentifier
        if ($result->ublExtensions !== null) {
            $this->assertArrayNotHasKey('additionalDocumentReferences', $result->ublExtensions);
        } else {
            $this->assertNull($result->ublExtensions);
        }
    }

    // === Line-level extensions ===

    public function testParsesLineInvoicePeriod(): void
    {
        $xml = $this->buildInvoiceXml('',
            '<cac:InvoicePeriod>
                <cbc:StartDate>2026-03-01</cbc:StartDate>
                <cbc:EndDate>2026-03-31</cbc:EndDate>
            </cac:InvoicePeriod>'
        );

        $result = $this->parser->parse($xml);

        $this->assertNotNull($result->lines[0]->ublExtensions);
        $period = $result->lines[0]->ublExtensions['invoicePeriod'];
        $this->assertSame('2026-03-01', $period['startDate']);
        $this->assertSame('2026-03-31', $period['endDate']);
    }

    public function testParsesLineAllowanceCharge(): void
    {
        $xml = $this->buildInvoiceXml('',
            '<cac:AllowanceCharge>
                <cbc:ChargeIndicator>false</cbc:ChargeIndicator>
                <cbc:AllowanceChargeReason>Volume discount</cbc:AllowanceChargeReason>
                <cbc:Amount currencyID="RON">5.00</cbc:Amount>
            </cac:AllowanceCharge>'
        );

        $result = $this->parser->parse($xml);

        $this->assertNotNull($result->lines[0]->ublExtensions);
        $ac = $result->lines[0]->ublExtensions['allowanceCharges'][0];
        $this->assertFalse($ac['chargeIndicator']);
        $this->assertSame('5.00', $ac['amount']);
        $this->assertSame('Volume discount', $ac['reason']);
    }

    public function testParsesAdditionalItemProperties(): void
    {
        // Need to inject into Item element — use a full line XML
        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"
         xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2"
         xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">
    <cbc:ID>TEST002</cbc:ID>
    <cbc:IssueDate>2026-03-01</cbc:IssueDate>
    <cbc:InvoiceTypeCode>380</cbc:InvoiceTypeCode>
    <cbc:DocumentCurrencyCode>RON</cbc:DocumentCurrencyCode>
    <cac:AccountingSupplierParty><cac:Party>
        <cac:PartyLegalEntity><cbc:RegistrationName>S</cbc:RegistrationName><cbc:CompanyID>1</cbc:CompanyID></cac:PartyLegalEntity>
    </cac:Party></cac:AccountingSupplierParty>
    <cac:AccountingCustomerParty><cac:Party>
        <cac:PartyLegalEntity><cbc:RegistrationName>B</cbc:RegistrationName><cbc:CompanyID>2</cbc:CompanyID></cac:PartyLegalEntity>
    </cac:Party></cac:AccountingCustomerParty>
    <cac:TaxTotal><cbc:TaxAmount currencyID="RON">0.00</cbc:TaxAmount></cac:TaxTotal>
    <cac:LegalMonetaryTotal>
        <cbc:TaxExclusiveAmount currencyID="RON">100.00</cbc:TaxExclusiveAmount>
        <cbc:PayableAmount currencyID="RON">100.00</cbc:PayableAmount>
    </cac:LegalMonetaryTotal>
    <cac:InvoiceLine>
        <cbc:ID>1</cbc:ID>
        <cbc:InvoicedQuantity unitCode="H87">1</cbc:InvoicedQuantity>
        <cbc:LineExtensionAmount currencyID="RON">100.00</cbc:LineExtensionAmount>
        <cac:Item>
            <cbc:Name>Widget</cbc:Name>
            <cac:ClassifiedTaxCategory>
                <cbc:ID>S</cbc:ID>
                <cbc:Percent>19.00</cbc:Percent>
                <cac:TaxScheme><cbc:ID>VAT</cbc:ID></cac:TaxScheme>
            </cac:ClassifiedTaxCategory>
            <cac:AdditionalItemProperty>
                <cbc:Name>Color</cbc:Name>
                <cbc:Value>Red</cbc:Value>
            </cac:AdditionalItemProperty>
            <cac:AdditionalItemProperty>
                <cbc:Name>Size</cbc:Name>
                <cbc:Value>XL</cbc:Value>
            </cac:AdditionalItemProperty>
            <cac:OriginCountry>
                <cbc:IdentificationCode>DE</cbc:IdentificationCode>
            </cac:OriginCountry>
        </cac:Item>
        <cac:Price><cbc:PriceAmount currencyID="RON">100.00</cbc:PriceAmount></cac:Price>
    </cac:InvoiceLine>
</Invoice>
XML;

        $result = $this->parser->parse($xml);

        $lineExt = $result->lines[0]->ublExtensions;
        $this->assertNotNull($lineExt);

        // Additional item properties
        $this->assertCount(2, $lineExt['additionalItemProperties']);
        $this->assertSame('Color', $lineExt['additionalItemProperties'][0]['name']);
        $this->assertSame('Red', $lineExt['additionalItemProperties'][0]['value']);
        $this->assertSame('Size', $lineExt['additionalItemProperties'][1]['name']);
        $this->assertSame('XL', $lineExt['additionalItemProperties'][1]['value']);

        // Origin country
        $this->assertSame('DE', $lineExt['originCountry']);
    }

    // === No extensions = null ===

    public function testNoExtensionsReturnsNull(): void
    {
        $xml = $this->buildInvoiceXml();
        $result = $this->parser->parse($xml);

        $this->assertNull($result->ublExtensions);
        $this->assertNull($result->lines[0]->ublExtensions);
    }

    // === Round-trip: generate then parse preserves data ===

    public function testParseMultipleAllowanceCharges(): void
    {
        $xml = $this->buildInvoiceXml(
            '<cac:AllowanceCharge>
                <cbc:ChargeIndicator>false</cbc:ChargeIndicator>
                <cbc:Amount currencyID="RON">50.00</cbc:Amount>
                <cac:TaxCategory><cbc:ID>S</cbc:ID><cbc:Percent>19.00</cbc:Percent><cac:TaxScheme><cbc:ID>VAT</cbc:ID></cac:TaxScheme></cac:TaxCategory>
            </cac:AllowanceCharge>
            <cac:AllowanceCharge>
                <cbc:ChargeIndicator>true</cbc:ChargeIndicator>
                <cbc:Amount currencyID="RON">25.00</cbc:Amount>
                <cac:TaxCategory><cbc:ID>S</cbc:ID><cbc:Percent>19.00</cbc:Percent><cac:TaxScheme><cbc:ID>VAT</cbc:ID></cac:TaxScheme></cac:TaxCategory>
            </cac:AllowanceCharge>'
        );

        $result = $this->parser->parse($xml);

        $this->assertCount(2, $result->ublExtensions['allowanceCharges']);
        $this->assertFalse($result->ublExtensions['allowanceCharges'][0]['chargeIndicator']);
        $this->assertTrue($result->ublExtensions['allowanceCharges'][1]['chargeIndicator']);
    }
}
