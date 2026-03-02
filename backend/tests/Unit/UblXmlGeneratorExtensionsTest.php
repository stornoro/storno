<?php

namespace App\Tests\Unit;

use App\Entity\Company;
use App\Entity\Client;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Enum\DocumentStatus;
use App\Enum\DocumentType;
use App\Enum\InvoiceDirection;
use App\Service\Anaf\UblXmlGenerator;
use App\Service\ExchangeRateService;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\FilterCollection;
use PHPUnit\Framework\TestCase;

class UblXmlGeneratorExtensionsTest extends TestCase
{
    private UblXmlGenerator $generator;

    protected function setUp(): void
    {
        $exchangeRateService = $this->createMock(ExchangeRateService::class);

        $filterCollection = $this->createMock(FilterCollection::class);
        $filterCollection->method('isEnabled')->willReturn(false);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getFilters')->willReturn($filterCollection);

        $this->generator = new UblXmlGenerator($exchangeRateService, $entityManager);
    }

    private function createInvoice(array $ublExtensions = null, array $lineExtensions = null): Invoice
    {
        $company = $this->createMock(Company::class);
        $company->method('getName')->willReturn('Test SRL');
        $company->method('getCif')->willReturn(12345678);
        $company->method('getAddress')->willReturn('Str. Test 1');
        $company->method('getCity')->willReturn('Cluj-Napoca');
        $company->method('getState')->willReturn('CJ');
        $company->method('getCountry')->willReturn('RO');
        $company->method('getEmail')->willReturn('test@test.ro');
        $company->method('getPhone')->willReturn('0740123456');
        $company->method('getBankAccount')->willReturn('RO12BTRL0000000000000');
        $company->method('getBankName')->willReturn('BT');
        $company->method('getBankBic')->willReturn(null);
        $company->method('getRegistrationNumber')->willReturn('J12/1234/2020');

        $client = $this->createMock(Client::class);
        $client->method('getName')->willReturn('Client SRL');
        $client->method('getCui')->willReturn('87654321');
        $client->method('getCnp')->willReturn(null);
        $client->method('getAddress')->willReturn('Str. Client 2');
        $client->method('getCity')->willReturn('Bucuresti');
        $client->method('getCounty')->willReturn('B');
        $client->method('getCountry')->willReturn('RO');
        $client->method('getEmail')->willReturn('client@test.ro');
        $client->method('getPhone')->willReturn('0740999888');
        $client->method('getPostalCode')->willReturn(null);

        $line = $this->createMock(InvoiceLine::class);
        $line->method('getDescription')->willReturn('Service IT');
        $line->method('getQuantity')->willReturn('1.0000');
        $line->method('getUnitOfMeasure')->willReturn('buc');
        $line->method('getUnitPrice')->willReturn('1000.00');
        $line->method('getVatRate')->willReturn('19.00');
        $line->method('getVatCategoryCode')->willReturn('S');
        $line->method('getVatAmount')->willReturn('190.00');
        $line->method('getLineTotal')->willReturn('1000.00');
        $line->method('getDiscount')->willReturn('0.00');
        $line->method('getBuyerAccountingRef')->willReturn(null);
        $line->method('getLineNote')->willReturn(null);
        $line->method('getBuyerItemIdentification')->willReturn(null);
        $line->method('getStandardItemIdentification')->willReturn(null);
        $line->method('getCpvCode')->willReturn(null);
        $line->method('getProductCode')->willReturn(null);
        $line->method('getUblExtensions')->willReturn($lineExtensions);

        $invoice = $this->createMock(Invoice::class);
        $invoice->method('getCompany')->willReturn($company);
        $invoice->method('getClient')->willReturn($client);
        $invoice->method('getDocumentType')->willReturn(DocumentType::INVOICE);
        $invoice->method('getNumber')->willReturn('TEST0001');
        $invoice->method('getIssueDate')->willReturn(new \DateTime('2026-03-01'));
        $invoice->method('getDueDate')->willReturn(new \DateTime('2026-03-31'));
        $invoice->method('getCurrency')->willReturn('RON');
        $invoice->method('getSubtotal')->willReturn('1000.00');
        $invoice->method('getVatTotal')->willReturn('190.00');
        $invoice->method('getTotal')->willReturn('1190.00');
        $invoice->method('getNotes')->willReturn(null);
        $invoice->method('getPaymentTerms')->willReturn(null);
        $invoice->method('getPaymentMethod')->willReturn('bank_transfer');
        $invoice->method('getExchangeRate')->willReturn(null);
        $invoice->method('getBusinessProcessType')->willReturn(null);
        $invoice->method('getBuyerAccountingReference')->willReturn(null);
        $invoice->method('getBuyerReference')->willReturn(null);
        $invoice->method('getOrderNumber')->willReturn(null);
        $invoice->method('getParentDocument')->willReturn(null);
        $invoice->method('getContractNumber')->willReturn(null);
        $invoice->method('getDespatchAdviceReference')->willReturn(null);
        $invoice->method('getReceivingAdviceReference')->willReturn(null);
        $invoice->method('getTenderOrLotReference')->willReturn(null);
        $invoice->method('getInvoicedObjectIdentifier')->willReturn(null);
        $invoice->method('getProjectReference')->willReturn(null);
        $invoice->method('getPayeeName')->willReturn(null);
        $invoice->method('getPayeeIdentifier')->willReturn(null);
        $invoice->method('getPayeeLegalRegistrationIdentifier')->willReturn(null);
        $invoice->method('getTaxPointDate')->willReturn(null);
        $invoice->method('getDeliveryLocation')->willReturn(null);
        $invoice->method('getLines')->willReturn(new ArrayCollection([$line]));
        $invoice->method('getUblExtensions')->willReturn($ublExtensions);

        return $invoice;
    }

    private function loadXml(string $xml): \DOMDocument
    {
        $dom = new \DOMDocument();
        $loaded = $dom->loadXML($xml);
        $this->assertTrue($loaded, 'Generated XML is well-formed');
        return $dom;
    }

    private function xpath(\DOMDocument $dom): \DOMXPath
    {
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('inv', 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2');
        $xpath->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        return $xpath;
    }

    // === InvoicePeriod ===

    public function testInvoicePeriodGenerated(): void
    {
        $invoice = $this->createInvoice([
            'invoicePeriod' => ['startDate' => '2026-03-01', 'endDate' => '2026-03-31', 'descriptionCode' => '35'],
        ]);

        $xml = $this->generator->generate($invoice);
        $dom = $this->loadXml($xml);
        $xpath = $this->xpath($dom);

        $startDate = $xpath->query('//cac:InvoicePeriod/cbc:StartDate');
        $this->assertSame(1, $startDate->length);
        $this->assertSame('2026-03-01', $startDate->item(0)->textContent);

        $endDate = $xpath->query('//cac:InvoicePeriod/cbc:EndDate');
        $this->assertSame('2026-03-31', $endDate->item(0)->textContent);

        $descCode = $xpath->query('//cac:InvoicePeriod/cbc:DescriptionCode');
        $this->assertSame('35', $descCode->item(0)->textContent);
    }

    public function testNoInvoicePeriodWhenNotSet(): void
    {
        $invoice = $this->createInvoice();

        $xml = $this->generator->generate($invoice);
        $dom = $this->loadXml($xml);
        $xpath = $this->xpath($dom);

        $period = $xpath->query('//cac:InvoicePeriod');
        $this->assertSame(0, $period->length);
    }

    // === Delivery ===

    public function testDeliveryGenerated(): void
    {
        $invoice = $this->createInvoice([
            'delivery' => [
                'actualDeliveryDate' => '2026-03-15',
                'deliveryAddress' => [
                    'streetName' => 'Str. Depozit 5',
                    'cityName' => 'Timisoara',
                    'countrySubentity' => 'RO-TM',
                    'countryCode' => 'RO',
                ],
            ],
        ]);

        $xml = $this->generator->generate($invoice);
        $dom = $this->loadXml($xml);
        $xpath = $this->xpath($dom);

        $date = $xpath->query('//cac:Delivery/cbc:ActualDeliveryDate');
        $this->assertSame(1, $date->length);
        $this->assertSame('2026-03-15', $date->item(0)->textContent);

        $street = $xpath->query('//cac:Delivery/cac:DeliveryLocation/cac:Address/cbc:StreetName');
        $this->assertSame('Str. Depozit 5', $street->item(0)->textContent);

        $country = $xpath->query('//cac:Delivery/cac:DeliveryLocation/cac:Address/cac:Country/cbc:IdentificationCode');
        $this->assertSame('RO', $country->item(0)->textContent);
    }

    // === AllowanceCharge (document-level) ===

    public function testDocumentAllowanceChargeGenerated(): void
    {
        $invoice = $this->createInvoice([
            'allowanceCharges' => [
                [
                    'chargeIndicator' => false,
                    'reasonCode' => '95',
                    'reason' => 'Discount 10%',
                    'amount' => '100.00',
                    'baseAmount' => '1000.00',
                    'multiplierFactorNumeric' => '10.00',
                    'taxCategoryCode' => 'S',
                    'taxRate' => '19.00',
                ],
            ],
        ]);

        $xml = $this->generator->generate($invoice);
        $dom = $this->loadXml($xml);
        $xpath = $this->xpath($dom);

        // Document-level AllowanceCharge (direct child of Invoice)
        $acNodes = $xpath->query('/inv:Invoice/cac:AllowanceCharge');
        $this->assertSame(1, $acNodes->length);

        $chargeIndicator = $xpath->query('/inv:Invoice/cac:AllowanceCharge/cbc:ChargeIndicator');
        $this->assertSame('false', $chargeIndicator->item(0)->textContent);

        $amount = $xpath->query('/inv:Invoice/cac:AllowanceCharge/cbc:Amount');
        $this->assertSame('100.00', $amount->item(0)->textContent);
        $this->assertSame('RON', $amount->item(0)->getAttribute('currencyID'));

        $taxCat = $xpath->query('/inv:Invoice/cac:AllowanceCharge/cac:TaxCategory/cbc:ID');
        $this->assertSame('S', $taxCat->item(0)->textContent);
    }

    public function testAllowanceAdjustsTaxTotal(): void
    {
        $invoice = $this->createInvoice([
            'allowanceCharges' => [
                [
                    'chargeIndicator' => false,
                    'amount' => '100.00',
                    'taxCategoryCode' => 'S',
                    'taxRate' => '19.00',
                ],
            ],
        ]);

        $xml = $this->generator->generate($invoice);
        $dom = $this->loadXml($xml);
        $xpath = $this->xpath($dom);

        // TaxTotal should be adjusted: 190.00 - 19.00 (19% of 100 allowance) = 171.00
        $taxAmount = $xpath->query('/inv:Invoice/cac:TaxTotal/cbc:TaxAmount[@currencyID="RON"]');
        $this->assertSame('171.00', $taxAmount->item(0)->textContent);

        // TaxSubtotal taxableAmount should be 1000 - 100 = 900
        $taxableAmount = $xpath->query('/inv:Invoice/cac:TaxTotal/cac:TaxSubtotal/cbc:TaxableAmount');
        $this->assertSame('900.00', $taxableAmount->item(0)->textContent);
    }

    public function testAllowanceAdjustsLegalMonetaryTotal(): void
    {
        $invoice = $this->createInvoice([
            'allowanceCharges' => [
                [
                    'chargeIndicator' => false,
                    'amount' => '100.00',
                    'taxCategoryCode' => 'S',
                    'taxRate' => '19.00',
                ],
            ],
        ]);

        $xml = $this->generator->generate($invoice);
        $dom = $this->loadXml($xml);
        $xpath = $this->xpath($dom);

        $lmt = '/inv:Invoice/cac:LegalMonetaryTotal';

        // LineExtensionAmount stays 1000
        $lineExt = $xpath->query("$lmt/cbc:LineExtensionAmount");
        $this->assertSame('1000.00', $lineExt->item(0)->textContent);

        // TaxExclusiveAmount = 1000 - 100 = 900
        $taxExcl = $xpath->query("$lmt/cbc:TaxExclusiveAmount");
        $this->assertSame('900.00', $taxExcl->item(0)->textContent);

        // TaxInclusiveAmount = 900 + 171 = 1071
        $taxIncl = $xpath->query("$lmt/cbc:TaxInclusiveAmount");
        $this->assertSame('1071.00', $taxIncl->item(0)->textContent);

        // AllowanceTotalAmount = 100
        $allowanceEl = $xpath->query("$lmt/cbc:AllowanceTotalAmount");
        $this->assertSame(1, $allowanceEl->length);
        $this->assertSame('100.00', $allowanceEl->item(0)->textContent);

        // PayableAmount = 1071
        $payable = $xpath->query("$lmt/cbc:PayableAmount");
        $this->assertSame('1071.00', $payable->item(0)->textContent);
    }

    public function testChargeIncreasesTotals(): void
    {
        $invoice = $this->createInvoice([
            'allowanceCharges' => [
                [
                    'chargeIndicator' => true,
                    'amount' => '50.00',
                    'taxCategoryCode' => 'S',
                    'taxRate' => '19.00',
                ],
            ],
        ]);

        $xml = $this->generator->generate($invoice);
        $dom = $this->loadXml($xml);
        $xpath = $this->xpath($dom);

        $lmt = '/inv:Invoice/cac:LegalMonetaryTotal';

        // TaxExclusiveAmount = 1000 + 50 = 1050
        $taxExcl = $xpath->query("$lmt/cbc:TaxExclusiveAmount");
        $this->assertSame('1050.00', $taxExcl->item(0)->textContent);

        // ChargeTotalAmount = 50
        $chargeEl = $xpath->query("$lmt/cbc:ChargeTotalAmount");
        $this->assertSame(1, $chargeEl->length);
        $this->assertSame('50.00', $chargeEl->item(0)->textContent);
    }

    // === PrepaidAmount ===

    public function testPrepaidAmountInLegalMonetaryTotal(): void
    {
        $invoice = $this->createInvoice([
            'prepaidAmount' => '200.00',
        ]);

        $xml = $this->generator->generate($invoice);
        $dom = $this->loadXml($xml);
        $xpath = $this->xpath($dom);

        $lmt = '/inv:Invoice/cac:LegalMonetaryTotal';

        $prepaid = $xpath->query("$lmt/cbc:PrepaidAmount");
        $this->assertSame(1, $prepaid->length);
        $this->assertSame('200.00', $prepaid->item(0)->textContent);

        // PayableAmount = 1190 - 200 = 990
        $payable = $xpath->query("$lmt/cbc:PayableAmount");
        $this->assertSame('990.00', $payable->item(0)->textContent);
    }

    public function testNoPrepaidWhenNotSet(): void
    {
        $invoice = $this->createInvoice();

        $xml = $this->generator->generate($invoice);
        $dom = $this->loadXml($xml);
        $xpath = $this->xpath($dom);

        $prepaid = $xpath->query('//cbc:PrepaidAmount');
        $this->assertSame(0, $prepaid->length);
    }

    // === AdditionalDocumentReferences ===

    public function testAdditionalDocRefsGenerated(): void
    {
        $invoice = $this->createInvoice([
            'additionalDocumentReferences' => [
                ['id' => 'ATT-001', 'documentTypeCode' => '916', 'documentDescription' => 'Timesheet'],
            ],
        ]);

        $xml = $this->generator->generate($invoice);
        $dom = $this->loadXml($xml);
        $xpath = $this->xpath($dom);

        $refs = $xpath->query('/inv:Invoice/cac:AdditionalDocumentReference');
        $this->assertGreaterThanOrEqual(1, $refs->length);

        // Find the one with our ID
        $found = false;
        foreach ($refs as $ref) {
            $id = $xpath->query('cbc:ID', $ref)->item(0)?->textContent;
            if ($id === 'ATT-001') {
                $found = true;
                $typeCode = $xpath->query('cbc:DocumentTypeCode', $ref)->item(0)?->textContent;
                $desc = $xpath->query('cbc:DocumentDescription', $ref)->item(0)?->textContent;
                $this->assertSame('916', $typeCode);
                $this->assertSame('Timesheet', $desc);
            }
        }
        $this->assertTrue($found, 'AdditionalDocumentReference with ID ATT-001 not found');
    }

    // === Line-level extensions ===

    public function testLineInvoicePeriodGenerated(): void
    {
        $invoice = $this->createInvoice(null, [
            'invoicePeriod' => ['startDate' => '2026-03-01', 'endDate' => '2026-03-31'],
        ]);

        $xml = $this->generator->generate($invoice);
        $dom = $this->loadXml($xml);
        $xpath = $this->xpath($dom);

        $linePeriod = $xpath->query('//cac:InvoiceLine/cac:InvoicePeriod/cbc:StartDate');
        $this->assertSame(1, $linePeriod->length);
        $this->assertSame('2026-03-01', $linePeriod->item(0)->textContent);
    }

    public function testLineAllowanceChargeGenerated(): void
    {
        $invoice = $this->createInvoice(null, [
            'allowanceCharges' => [
                ['chargeIndicator' => false, 'amount' => '5.00', 'reason' => 'Volume discount'],
            ],
        ]);

        $xml = $this->generator->generate($invoice);
        $dom = $this->loadXml($xml);
        $xpath = $this->xpath($dom);

        $lineAc = $xpath->query('//cac:InvoiceLine/cac:AllowanceCharge/cbc:Amount');
        $this->assertSame(1, $lineAc->length);
        $this->assertSame('5.00', $lineAc->item(0)->textContent);
    }

    public function testLineAdditionalItemPropertyGenerated(): void
    {
        $invoice = $this->createInvoice(null, [
            'additionalItemProperties' => [
                ['name' => 'Color', 'value' => 'Red'],
            ],
        ]);

        $xml = $this->generator->generate($invoice);
        $dom = $this->loadXml($xml);
        $xpath = $this->xpath($dom);

        $propName = $xpath->query('//cac:InvoiceLine/cac:Item/cac:AdditionalItemProperty/cbc:Name');
        $this->assertSame(1, $propName->length);
        $this->assertSame('Color', $propName->item(0)->textContent);

        $propValue = $xpath->query('//cac:InvoiceLine/cac:Item/cac:AdditionalItemProperty/cbc:Value');
        $this->assertSame('Red', $propValue->item(0)->textContent);
    }

    public function testLineOriginCountryGenerated(): void
    {
        $invoice = $this->createInvoice(null, [
            'originCountry' => 'DE',
        ]);

        $xml = $this->generator->generate($invoice);
        $dom = $this->loadXml($xml);
        $xpath = $this->xpath($dom);

        $origin = $xpath->query('//cac:InvoiceLine/cac:Item/cac:OriginCountry/cbc:IdentificationCode');
        $this->assertSame(1, $origin->length);
        $this->assertSame('DE', $origin->item(0)->textContent);
    }

    // === No extensions = no extra elements ===

    public function testNoExtensionsProducesCleanXml(): void
    {
        $invoice = $this->createInvoice();

        $xml = $this->generator->generate($invoice);
        $dom = $this->loadXml($xml);
        $xpath = $this->xpath($dom);

        $this->assertSame(0, $xpath->query('//cac:InvoicePeriod')->length);
        $this->assertSame(0, $xpath->query('//cac:Delivery')->length);
        $this->assertSame(0, $xpath->query('/inv:Invoice/cac:AllowanceCharge')->length);
        $this->assertSame(0, $xpath->query('//cbc:PrepaidAmount')->length);
        $this->assertSame(0, $xpath->query('//cbc:AllowanceTotalAmount')->length);
        $this->assertSame(0, $xpath->query('//cbc:ChargeTotalAmount')->length);
        $this->assertSame(0, $xpath->query('//cac:AdditionalItemProperty')->length);
        $this->assertSame(0, $xpath->query('//cac:OriginCountry')->length);
    }
}
