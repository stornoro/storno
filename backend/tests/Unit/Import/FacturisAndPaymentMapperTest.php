<?php

namespace App\Tests\Unit\Import;

use App\Service\Import\Mapper\FacturisInvoiceMapper;
use App\Service\Import\Mapper\PaymentMapper;
use PHPUnit\Framework\TestCase;

class FacturisAndPaymentMapperTest extends TestCase
{
    private const JOURNAL = ['Nr. Document', 'Nume Client', 'Cod Fiscal', 'Data', 'Mod de Plata', 'Moneda', 'Observatii 1', 'Cod Produs', 'Nume produs', 'UM', 'Cantitate', 'Pret fara TVA', 'Valoare fara TVA', 'TVA', 'Cota TVA'];

    public function testThePlainJournalKeepsItsLayout(): void
    {
        $mapper = new FacturisInvoiceMapper();

        self::assertSame('facturis', $mapper->getSource());
        self::assertSame('invoices_issued', $mapper->getImportType());
        self::assertSame(1.0, $mapper->detectConfidence(self::JOURNAL));
        self::assertSame($mapper->getDefaultMapping(), $mapper->suggestMapping(self::JOURNAL));

        $row = array_combine(self::JOURNAL, ['FCT 12', 'Client Exemplu SRL', 'RO12345678', '15.01.2025', 'OP', 'RON', '', 'S1', 'Abonament', 'buc', '2', '50,00', '100,00', '21,00', '21']);
        $mapped = $mapper->mapRow($row, $mapper->suggestMapping(self::JOURNAL));

        self::assertSame('FCT 12', $mapped['number']);
        self::assertSame('2025-01-15', $mapped['issueDate']);
        self::assertSame('RO12345678', $mapped['receiverCif']);
        self::assertSame('outgoing', $mapped['direction']);
        self::assertSame('121.00', $mapped['total']);
        self::assertSame('21.00', $mapped['lines'][0]['vatRate']);
        self::assertTrue($mapped['_createClient']);
        self::assertArrayNotHasKey('_unpaidAmount', $mapped);
    }

    public function testAddedColumnsBringTheDueDateTheCollectionAndTheStorno(): void
    {
        $mapper = new FacturisInvoiceMapper();
        $headers = [...self::JOURNAL, 'Scadență', 'Rest de plată', 'Factura stornata'];
        $mapping = $mapper->suggestMapping($headers);

        self::assertSame('dueDate', $mapping['Scadență']);
        self::assertSame('unpaidAmount', $mapping['Rest de plată']);
        self::assertSame('stornoOf', $mapping['Factura stornata']);

        $row = array_combine($headers, ['FCT 13', 'Client Exemplu SRL', '12345678', '15.01.2025', 'OP', 'RON', '', 'S1', 'Abonament', 'buc', '-1', '100', '-100', '-21', '21', '30.01.2025', '0', 'FCT 12']);
        $mapped = $mapper->mapRow($row, $mapping);

        self::assertSame('2025-01-30', $mapped['dueDate']);
        self::assertSame('0.00', $mapped['_unpaidAmount']);
        self::assertSame('FCT 12', $mapped['_stornoOf']);
        self::assertSame('-121.00', $mapped['total']);
    }

    public function testPaymentRows(): void
    {
        $mapper = new PaymentMapper('facturis');
        $headers = ['Serie', 'Nr. factură', 'Data încasării', 'Sumă încasată', 'Mod de plată', 'Nr. chitanță', 'Client'];
        $mapping = $mapper->suggestMapping($headers);

        self::assertSame(
            ['Serie' => 'series', 'Nr. factură' => 'invoiceNumber', 'Data încasării' => 'paymentDate', 'Sumă încasată' => 'amount', 'Mod de plată' => 'paymentMethod', 'Nr. chitanță' => 'reference'],
            $mapping,
        );
        self::assertSame(1.0, $mapper->detectConfidence($headers));

        $mapped = $mapper->mapRow(array_combine($headers, ['FCT', '12.0', '05/03/2025', '1.234,50 lei', 'Numerar', 'CH 7', 'x']), $mapping);
        self::assertSame(['invoiceNumber' => 'FCT12', 'amount' => '1234.50', 'paymentDate' => '2025-03-05', 'paymentMethod' => 'cash', 'reference' => 'CH 7'], $mapped);

        // what cannot be read is kept, so the row is reported instead of imported wrong
        $bad = $mapper->mapRow(['Factura' => 'FCT12', 'Suma' => 'n/a'], ['Factura' => 'invoiceNumber', 'Suma' => 'amount']);
        self::assertSame('n/a', $bad['amount']);
    }
}
