<?php

namespace App\Tests\Unit;

use App\Entity\Company;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Service\Export\SagaXmlExportService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the SAGA invoice XML generator.
 *
 * generateInvoicesXml() was migrated from DOMDocument to XMLWriter for the
 * accounting export. These tests pin the output format that SAGA expects:
 * 2-space indent, &/</> escaped but quotes left raw, empty elements rendered
 * as <Name></Name> (never self-closing), and the Antet/Detalii structure.
 *
 * The service is built without its constructor — generateInvoicesXml does not
 * touch the injected ExchangeRateService.
 */
class SagaXmlExportServiceTest extends TestCase
{
    private SagaXmlExportService $service;

    protected function setUp(): void
    {
        $this->service = (new \ReflectionClass(SagaXmlExportService::class))
            ->newInstanceWithoutConstructor();
    }

    private function makeCompany(): Company
    {
        $c = new Company();
        $c->setName('Acme & Co <SRL>');
        $c->setCif(12345678);
        $c->setCountry('RO');
        $c->setCity('Cluj');
        $c->setState('CJ');

        return $c;
    }

    private function makeInvoice(string $number, string $typeCode, string $discount): Invoice
    {
        $inv = new Invoice();
        $inv->setCompany($this->makeCompany());
        $inv->setNumber($number);
        $inv->setIssueDate(new \DateTime('2026-05-15'));
        $inv->setDueDate(new \DateTime('2026-06-15'));
        $inv->setCurrency('RON');
        $inv->setReceiverName('Client & "Co" <2>');
        $inv->setReceiverCif('RO99887766');
        $inv->setDiscount($discount);
        $inv->setInvoiceTypeCode($typeCode);

        $line = new InvoiceLine();
        $line->setDescription('Produs <1> & "test"');
        $line->setUnitOfMeasure('buc');
        $line->setQuantity('2.000');
        $line->setUnitPrice('100.50');
        $line->setLineTotal('201.00');
        $line->setVatRate('19');
        $line->setVatAmount('38.19');
        $inv->addLine($line);

        return $inv;
    }

    public function testOutputIsWellFormedXml(): void
    {
        $xml = $this->service->generateInvoicesXml([$this->makeInvoice('UEP2026000001', '380', '0')], $this->makeCompany());

        $doc = new \DOMDocument();
        $this->assertTrue($doc->loadXML($xml), 'Generated SAGA invoice XML must be well-formed');
        $this->assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $xml);
    }

    public function testStructureAndIndentation(): void
    {
        $xml = $this->service->generateInvoicesXml([$this->makeInvoice('UEP2026000001', '380', '0')], $this->makeCompany());

        $this->assertStringContainsString("<Facturi>\n  <Factura>\n    <Antet>\n", $xml);
        $this->assertStringContainsString('<Detalii>', $xml);
        $this->assertStringContainsString('<Continut>', $xml);
        $this->assertStringContainsString('<FacturaNumar>UEP2026000001</FacturaNumar>', $xml);
        $this->assertStringContainsString('<FacturaID>2026000001</FacturaID>', $xml);
    }

    public function testEscapingMatchesDomTextNode(): void
    {
        $xml = $this->service->generateInvoicesXml([$this->makeInvoice('UEP2026000001', '380', '0')], $this->makeCompany());

        // & < > escaped; quotes left raw (as a DOM text node would render them).
        $this->assertStringContainsString('<FurnizorNume>Acme &amp; Co &lt;SRL&gt;</FurnizorNume>', $xml);
        $this->assertStringContainsString('<ClientNume>Client &amp; "Co" &lt;2&gt;</ClientNume>', $xml);
        $this->assertStringContainsString('<Descriere>Produs &lt;1&gt; &amp; "test"</Descriere>', $xml);
        $this->assertStringNotContainsString('&quot;', $xml);
    }

    public function testEmptyElementsAreNotSelfClosing(): void
    {
        $xml = $this->service->generateInvoicesXml([$this->makeInvoice('UEP2026000001', '380', '0')], $this->makeCompany());

        $this->assertStringContainsString('<FurnizorCapital></FurnizorCapital>', $xml);
        $this->assertStringNotContainsString('<FurnizorCapital/>', $xml);
    }

    public function testReverseChargeTypeAndDiscount(): void
    {
        $xml = $this->service->generateInvoicesXml([$this->makeInvoice('UEP2026000002', '389', '15.50')], $this->makeCompany(), true);

        $this->assertStringContainsString('<FacturaTaxareInversa>Da</FacturaTaxareInversa>', $xml);
        $this->assertStringContainsString('<FacturaTip>T</FacturaTip>', $xml);
        $this->assertStringContainsString('<FacturaDiscount>15.50</FacturaDiscount>', $xml);
    }

    public function testDiscountOmittedWhenZeroOrFlagOff(): void
    {
        $xmlFlagOff = $this->service->generateInvoicesXml([$this->makeInvoice('UEP2026000002', '389', '15.50')], $this->makeCompany(), false);
        $this->assertStringNotContainsString('<FacturaDiscount>', $xmlFlagOff);

        $xmlZero = $this->service->generateInvoicesXml([$this->makeInvoice('UEP2026000001', '380', '0')], $this->makeCompany(), true);
        $this->assertStringNotContainsString('<FacturaDiscount>', $xmlZero);
    }
}
