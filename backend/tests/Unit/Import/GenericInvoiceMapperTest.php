<?php

namespace App\Tests\Unit\Import;

use App\Service\Import\Mapper\GenericInvoiceMapper;
use PHPUnit\Framework\TestCase;

class GenericInvoiceMapperTest extends TestCase
{
    public function testSuggestsMappingForOneRowPerInvoiceReport(): void
    {
        $mapper = new GenericInvoiceMapper('smartbill');
        $headers = ['Serie', 'Număr', 'Data', 'Scadență', 'Client', 'CIF', 'Valoare fără TVA', 'TVA', 'Total', 'Monedă', 'Rest de plată', 'Necunoscut'];

        $this->assertSame([
            'Serie' => 'series',
            'Număr' => 'number',
            'Data' => 'issueDate',
            'Scadență' => 'dueDate',
            'Client' => 'receiverName',
            'CIF' => 'receiverCif',
            'Valoare fără TVA' => 'subtotal',
            'TVA' => 'vatTotal',
            'Total' => 'total',
            'Monedă' => 'currency',
            'Rest de plată' => 'unpaidAmount',
        ], $mapper->suggestMapping($headers));
        $this->assertSame(0.9, $mapper->detectConfidence($headers));
        $this->assertSame('smartbill', $mapper->getSource());
        $this->assertSame('invoices_issued', $mapper->getImportType());
    }

    public function testPerLineReportPutsAmountsOnTheLine(): void
    {
        $mapper = new GenericInvoiceMapper();
        $mapping = $mapper->suggestMapping(['Nr. factura', 'Data factura', 'Nume client', 'Cod fiscal', 'Denumire produs', 'Cantitate', 'UM', 'Pret unitar', 'TVA (%)', 'Valoare', 'Valoare TVA', 'Total']);

        $this->assertSame('lineDescription', $mapping['Denumire produs']);
        $this->assertSame('lineVatRate', $mapping['TVA (%)']);
        $this->assertSame('lineTotal', $mapping['Valoare']);
        $this->assertSame('lineVatAmount', $mapping['Valoare TVA']);
        $this->assertSame('lineTotalGross', $mapping['Total']);
    }

    public function testMapsOneRowPerInvoice(): void
    {
        $mapper = new GenericInvoiceMapper('smartbill');
        $mapping = $mapper->suggestMapping(['Serie', 'Numar', 'Data', 'Client', 'CIF', 'Valoare', 'TVA', 'Total', 'Moneda', 'Rest']);
        $row = ['Serie' => 'FCT', 'Numar' => '0125', 'Data' => '15/10/2026', 'Client' => 'Exemplu SRL', 'CIF' => 'RO 12345678', 'Valoare' => '1.000,00', 'TVA' => '210,00', 'Total' => '1.210,00', 'Moneda' => 'Lei', 'Rest' => '210,00'];

        $mapped = $mapper->mapRow($row, $mapping);

        $this->assertSame('FCT0125', $mapped['number']);
        $this->assertSame('2026-10-15', $mapped['issueDate']);
        $this->assertSame('RO12345678', $mapped['receiverCif']);
        $this->assertSame('outgoing', $mapped['direction']);
        $this->assertSame('RON', $mapped['currency']);
        $this->assertSame(['1000.00', '210.00', '1210.00'], [$mapped['subtotal'], $mapped['vatTotal'], $mapped['total']]);
        $this->assertSame('210.00', $mapped['_unpaidAmount']);
        $this->assertTrue($mapped['_createClient']);
        $this->assertCount(1, $mapped['lines']);
        $this->assertSame('Conform facturii FCT0125', $mapped['lines'][0]['description']);
        $this->assertSame('1000.00', $mapped['lines'][0]['lineTotal']);
        $this->assertSame('21.00', $mapped['lines'][0]['vatRate']);
        $this->assertSame('S', $mapped['lines'][0]['vatCategoryCode']);
    }

    public function testMapsPerLineRowFromItsOwnAmounts(): void
    {
        $mapper = new GenericInvoiceMapper();
        $mapping = ['Nr' => 'number', 'Produs' => 'lineDescription', 'Cant' => 'lineQuantity', 'Pret' => 'lineUnitPrice', 'Cota' => 'lineVatRate', 'Total factura' => 'total', 'Stare' => 'state'];
        $row = ['Nr' => 'A12', 'Produs' => 'Servicii', 'Cant' => '3', 'Pret' => '100.50', 'Cota' => '21%', 'Total factura' => '9999', 'Stare' => 'Încasată'];

        $mapped = $mapper->mapRow($row, $mapping);

        // the invoice total repeated on each row is not this row's amount
        $this->assertSame(['301.50', '63.32', '364.82'], [$mapped['subtotal'], $mapped['vatTotal'], $mapped['total']]);
        $this->assertSame('3', $mapped['lines'][0]['quantity']);
        $this->assertSame('100.50', $mapped['lines'][0]['unitPrice']);
        $this->assertTrue($mapped['_paid']);
    }

    public function testGrossOnlyIsLeftToTheCompanyRate(): void
    {
        $mapped = (new GenericInvoiceMapper())->mapRow(['Nr' => '7', 'Total' => '121'], ['Nr' => 'number', 'Total' => 'total']);

        $this->assertTrue($mapped['_vatIncludedUnknownRate']);
        $this->assertSame('121.00', $mapped['lines'][0]['lineTotal']);
        $this->assertArrayNotHasKey('vatRate', $mapped['lines'][0]);
    }

    public function testStatesAndDates(): void
    {
        $mapper = new GenericInvoiceMapper();
        $mapping = ['Nr' => 'number', 'Total' => 'total', 'TVA' => 'vatTotal', 'Stare' => 'state', 'Data' => 'issueDate'];

        $unpaid = $mapper->mapRow(['Nr' => '1', 'Total' => '100', 'TVA' => '0', 'Stare' => 'Neîncasată', 'Data' => '46310'], $mapping);
        $this->assertArrayNotHasKey('_paid', $unpaid);
        $this->assertSame('2026-10-15', $unpaid['issueDate']);

        $cancelled = $mapper->mapRow(['Nr' => '2', 'Total' => '100', 'TVA' => '0', 'Stare' => 'Anulată', 'Data' => '15.10.2026'], $mapping);
        $this->assertSame('cancelled', $cancelled['status']);
        $this->assertSame('2026-10-15', $cancelled['issueDate']);

        $this->assertSame('2026-01-05', GenericInvoiceMapper::normalizeDate('2026-01-05 00:00:00'));
        $this->assertNull(GenericInvoiceMapper::normalizeDate('31/02/2026'));
        $this->assertNull(GenericInvoiceMapper::normalizeDate('ieri'));
    }

    public function testReceivedInvoicesReadTheSupplier(): void
    {
        $mapper = new GenericInvoiceMapper('generic', GenericInvoiceMapper::TYPE_RECEIVED);
        $mapping = $mapper->suggestMapping(['Numar factura', 'Furnizor', 'CUI', 'Total', 'Client']);

        $this->assertSame('senderName', $mapping['Furnizor']);
        $this->assertSame('senderCif', $mapping['CUI']);
        $this->assertArrayNotHasKey('Client', $mapping);

        $mapped = $mapper->mapRow(['Numar factura' => 'X1', 'Furnizor' => 'Furnizor Exemplu SRL', 'CUI' => '87654321', 'Total' => '50'], $mapping);
        $this->assertSame('incoming', $mapped['direction']);
        $this->assertArrayNotHasKey('_createClient', $mapped);
    }

    public function testTemplateHeadersMapBack(): void
    {
        $mapper = new GenericInvoiceMapper();
        $mapping = $mapper->suggestMapping(array_values($mapper->getTargetFields()));

        $this->assertSame(array_keys($mapper->getTargetFields()), array_values($mapping));
    }
}
