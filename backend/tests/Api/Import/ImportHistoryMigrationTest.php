<?php

declare(strict_types=1);

namespace App\Tests\Api\Import;

/**
 * Moving a company's history over from another program: the invoice journal with its due
 * dates, collections and stornos, narrowed to a period and to chosen clients; the collections
 * with their real dates; and e-Factura XML files as history.
 */
final class ImportHistoryMigrationTest extends ImportSourceTestCase
{
    private const FILTER = ['dateFrom' => '2025-01-01', 'clientCifs' => ['10000001']];

    public function testJournalKeepsDueDatesCollectionsAndStornosAndHonoursTheFilters(): void
    {
        $job = $this->runImport('facturis', 'invoices_issued', 'facturis-journal-history.csv', self::FILTER);

        $this->assertSame(3, $job['createdCount']);
        $this->assertSame(2, $job['skippedCount'], 'one invoice is before the period, one belongs to another client');

        $invoices = $this->historyOf($job['id']);
        $this->assertSame(['MIG0001', 'MIG0002', 'MIG0004'], array_keys($invoices));

        $this->assertSame('242.00', $invoices['MIG0001']['total']);
        $this->assertSame('2025-01-30', $invoices['MIG0001']['due_date']);
        $this->assertSame('242.00', $invoices['MIG0001']['amount_paid']);
        $this->assertCount(2, $this->linesOf('MIG0001', $job['id']));

        $this->assertSame('partially_paid', $invoices['MIG0002']['status']);
        $this->assertSame('100.00', $invoices['MIG0002']['amount_paid']);
        $this->assertSame('2025-02-28', $invoices['MIG0002']['due_date']);

        // the storno is linked to the invoice it reverses
        $this->assertSame('-121.00', $invoices['MIG0004']['total']);
        $this->assertSame('refund', $invoices['MIG0004']['status']);
        $this->assertSame($invoices['MIG0001']['id'], $invoices['MIG0004']['parent_document_id']);
        $this->assertSame('refunded', $invoices['MIG0001']['status']);

        // nothing is queued for ANAF
        $this->assertSame(0, (int) $this->db()->fetchOne(
            'SELECT COUNT(*) FROM invoice WHERE import_job_id = :j AND (scheduled_send_at IS NOT NULL OR anaf_upload_id IS NOT NULL)',
            ['j' => $job['id']],
        ));

        // a second import of the same file creates nothing
        $again = $this->runImport('facturis', 'invoices_issued', 'facturis-journal-history.csv', self::FILTER);
        $this->assertSame(0, $again['createdCount']);

        $this->revert($again['id']);
        $this->revert($job['id']);
        $this->assertCount(0, $this->invoicesOf($job['id']));
    }

    public function testInvalidFilterIsRefused(): void
    {
        $uploaded = $this->upload('facturis', 'invoices_issued', 'facturis-journal-history.csv');
        $jobId = $uploaded['job']['id'];
        $this->apiPatch('/api/v1/import/' . $jobId . '/mapping', ['columnMapping' => $uploaded['job']['suggestedMapping']], $this->headers);

        $this->apiPost('/api/v1/import/' . $jobId . '/execute', ['importOptions' => ['dateFrom' => '01.01.2025']], $this->headers);
        $this->assertResponseStatusCodeSame(422);

        $this->apiPost('/api/v1/import/' . $jobId . '/execute', ['importOptions' => ['dateFrom' => '2025-02-01', 'dateTo' => '2025-01-01']], $this->headers);
        $this->assertResponseStatusCodeSame(422);
    }

    public function testPaymentsReplaceThePlaceholderAndRevertPutsItBack(): void
    {
        $invoicesJob = $this->runImport('facturis', 'invoices_issued', 'facturis-journal-history.csv', self::FILTER);
        $invoiceId = $this->historyOf($invoicesJob['id'])['MIG0002']['id'];

        $placeholder = $this->paymentsOf($invoiceId);
        $this->assertCount(1, $placeholder);
        $this->assertSame('100.00', $placeholder[0]['amount']);
        $this->assertSame('2025-02-15', $placeholder[0]['payment_date'], 'the journal only knows the invoice date');

        $job = $this->importPayments();
        $this->assertSame(2, $job['createdCount']);
        $this->assertSame(1, $job['errorCount'], 'the payment of an unknown invoice is reported');

        $payments = $this->paymentsOf($invoiceId);
        $this->assertSame(
            [['100.00', '2025-02-20', 'bank_transfer', 'OP 11'], ['142.00', '2025-03-05', 'cash', 'CH 7']],
            array_map(static fn (array $p) => [$p['amount'], $p['payment_date'], $p['payment_method'], $p['reference']], $payments),
        );
        $invoice = $this->historyOf($invoicesJob['id'])['MIG0002'];
        $this->assertSame('242.00', $invoice['amount_paid']);
        $this->assertSame('paid', $invoice['status']);
        $this->assertSame('2025-03-05', substr((string) $invoice['paid_at'], 0, 10));

        // the same file again adds nothing
        $again = $this->importPayments();
        $this->assertSame(0, $again['createdCount']);
        $this->assertSame(2, $again['skippedCount']);
        $this->assertCount(2, $this->paymentsOf($invoiceId));
        $this->revert($again['id']);
        $this->assertCount(2, $this->paymentsOf($invoiceId), 'reverting a job that added nothing removes nothing');

        $this->revert($job['id']);
        $restored = $this->paymentsOf($invoiceId);
        $this->assertCount(1, $restored);
        $this->assertSame(['100.00', '2025-02-15', 'Import automat'], [$restored[0]['amount'], $restored[0]['payment_date'], $restored[0]['reference']]);
        $invoice = $this->historyOf($invoicesJob['id'])['MIG0002'];
        $this->assertSame('100.00', $invoice['amount_paid']);
        $this->assertSame('partially_paid', $invoice['status']);
        $this->assertNull($invoice['paid_at']);

        $this->revert($invoicesJob['id']);
    }

    public function testEFacturaArchiveBecomesHistory(): void
    {
        $cif = (string) $this->db()->fetchOne('SELECT cif FROM company WHERE id = :id', ['id' => $this->companyId]);

        $zipPath = sys_get_temp_dir() . '/' . uniqid('efactura_', true) . '.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString('4001.xml', self::ubl('Invoice', 'UBL0001', '2025-04-10', $cif, 2, null));
        $zip->addFromString('semnatura_4001.xml', '<?xml version="1.0"?><header xmlns="mfp:anaf:dgti:efactura:mesajEroriFactuta:v1"/>');
        $zip->addFromString('4002.xml', self::ubl('CreditNote', 'UBL0002', '2025-04-20', $cif, 1, 'UBL0001'));
        $zip->addFromString('4003.xml', self::ubl('Invoice', 'UBL0003', '2025-04-21', '99999999', 1, null));
        $zip->close();

        try {
            $uploaded = $this->upload('efactura', 'invoices_issued', $zipPath);
            $jobId = $uploaded['job']['id'];
            $this->assertSame('ubl_zip', $uploaded['job']['fileFormat']);
            $this->apiPatch('/api/v1/import/' . $jobId . '/mapping', ['columnMapping' => $uploaded['job']['suggestedMapping']], $this->headers);
            $this->apiPost('/api/v1/import/' . $jobId . '/execute', [], $this->headers);
            $this->assertResponseStatusCodeSame(200, $this->client->getResponse()->getContent());
            $job = $this->apiGet('/api/v1/import/' . $jobId, $this->headers)['job'];
        } finally {
            @unlink($zipPath);
        }

        $this->assertSame('completed', $job['status']);
        $this->assertSame(2, $job['createdCount']);
        $this->assertSame(1, $job['errorCount'], 'the invoice issued by another company is refused');

        $invoices = $this->historyOf($jobId);
        $this->assertSame(['UBL0001', 'UBL0002'], array_keys($invoices));
        $this->assertSame('242.00', $invoices['UBL0001']['total']);
        $this->assertSame('2025-04-25', $invoices['UBL0001']['due_date']);
        $this->assertSame('synced', $invoices['UBL0001']['status'] === 'refunded' ? 'synced' : $invoices['UBL0001']['status']);
        $this->assertCount(2, $this->linesOf('UBL0001', $jobId));
        $this->assertSame('Client XML Exemplu SRL', $this->invoicesOf($jobId)[0]['receiver_name']);

        // a credit note is a negative invoice linked to the one it corrects
        $this->assertSame('-121.00', $invoices['UBL0002']['total']);
        $this->assertSame($invoices['UBL0001']['id'], $invoices['UBL0002']['parent_document_id']);
        $this->assertSame('refunded', $invoices['UBL0001']['status']);

        $this->revert($jobId);
        $this->assertCount(0, $this->invoicesOf($jobId));
    }

    /** @return array<string, mixed> the finished job, errors allowed */
    private function importPayments(): array
    {
        $uploaded = $this->upload('facturis', 'payments', 'payments-history.csv');
        $jobId = $uploaded['job']['id'];
        $this->assertSame(
            ['Factura' => 'invoiceNumber', 'Data incasarii' => 'paymentDate', 'Suma' => 'amount', 'Mod plata' => 'paymentMethod', 'Nr chitanta' => 'reference'],
            $uploaded['job']['suggestedMapping'],
        );
        $this->apiPatch('/api/v1/import/' . $jobId . '/mapping', ['columnMapping' => $uploaded['job']['suggestedMapping']], $this->headers);
        $this->apiPost('/api/v1/import/' . $jobId . '/execute', [], $this->headers);
        $this->assertResponseStatusCodeSame(200, $this->client->getResponse()->getContent());

        $job = $this->apiGet('/api/v1/import/' . $jobId, $this->headers)['job'];
        $this->assertSame('completed', $job['status'], json_encode($job['errors'], JSON_UNESCAPED_UNICODE));

        return $job;
    }

    /** @return array<string, array<string, mixed>> by invoice number */
    private function historyOf(string $jobId): array
    {
        $rows = $this->db()->fetchAllAssociative(
            'SELECT id, number, status, total, amount_paid, due_date, paid_at, parent_document_id FROM invoice WHERE import_job_id = :j ORDER BY number',
            ['j' => $jobId],
        );

        return array_column($rows, null, 'number');
    }

    /** @return array<int, array<string, mixed>> */
    private function paymentsOf(string $invoiceId): array
    {
        return $this->db()->fetchAllAssociative(
            'SELECT amount, payment_date, payment_method, reference FROM payment WHERE invoice_id = :id ORDER BY payment_date, amount',
            ['id' => $invoiceId],
        );
    }

    private static function ubl(string $root, string $number, string $date, string $sellerCif, int $lines, ?string $reference): string
    {
        $isCredit = $root === 'CreditNote';
        $lineTag = $isCredit ? 'CreditNoteLine' : 'InvoiceLine';
        $qtyTag = $isCredit ? 'CreditedQuantity' : 'InvoicedQuantity';
        $net = 100 * $lines;
        $vat = 21 * $lines;

        $lineXml = '';
        for ($i = 1; $i <= $lines; $i++) {
            $lineXml .= <<<XML
              <cac:{$lineTag}>
                <cbc:ID>{$i}</cbc:ID>
                <cbc:{$qtyTag} unitCode="H87">1</cbc:{$qtyTag}>
                <cbc:LineExtensionAmount currencyID="RON">100.00</cbc:LineExtensionAmount>
                <cac:Item>
                  <cbc:Name>Serviciu {$i}</cbc:Name>
                  <cac:ClassifiedTaxCategory><cbc:ID>S</cbc:ID><cbc:Percent>21</cbc:Percent><cac:TaxScheme><cbc:ID>VAT</cbc:ID></cac:TaxScheme></cac:ClassifiedTaxCategory>
                </cac:Item>
                <cac:Price><cbc:PriceAmount currencyID="RON">100.00</cbc:PriceAmount></cac:Price>
              </cac:{$lineTag}>

            XML;
        }
        $billing = $reference === null ? '' : "<cac:BillingReference><cac:InvoiceDocumentReference><cbc:ID>{$reference}</cbc:ID></cac:InvoiceDocumentReference></cac:BillingReference>";
        $due = $isCredit ? '' : '<cbc:DueDate>2025-04-25</cbc:DueDate>';
        $total = $net + $vat;

        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <{$root} xmlns="urn:oasis:names:specification:ubl:schema:xsd:{$root}-2" xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2" xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">
              <cbc:ID>{$number}</cbc:ID>
              <cbc:IssueDate>{$date}</cbc:IssueDate>
              {$due}
              <cbc:DocumentCurrencyCode>RON</cbc:DocumentCurrencyCode>
              {$billing}
              <cac:AccountingSupplierParty><cac:Party>
                <cac:PostalAddress><cbc:StreetName>Str. Exemplu 1</cbc:StreetName><cbc:CityName>Bucuresti</cbc:CityName><cbc:CountrySubentity>RO-B</cbc:CountrySubentity><cac:Country><cbc:IdentificationCode>RO</cbc:IdentificationCode></cac:Country></cac:PostalAddress>
                <cac:PartyTaxScheme><cbc:CompanyID>RO{$sellerCif}</cbc:CompanyID><cac:TaxScheme><cbc:ID>VAT</cbc:ID></cac:TaxScheme></cac:PartyTaxScheme>
                <cac:PartyLegalEntity><cbc:RegistrationName>Firma Emitenta Exemplu SRL</cbc:RegistrationName><cbc:CompanyID>{$sellerCif}</cbc:CompanyID></cac:PartyLegalEntity>
              </cac:Party></cac:AccountingSupplierParty>
              <cac:AccountingCustomerParty><cac:Party>
                <cac:PostalAddress><cbc:StreetName>Str. Client 2</cbc:StreetName><cbc:CityName>Cluj-Napoca</cbc:CityName><cbc:CountrySubentity>RO-CJ</cbc:CountrySubentity><cac:Country><cbc:IdentificationCode>RO</cbc:IdentificationCode></cac:Country></cac:PostalAddress>
                <cac:PartyTaxScheme><cbc:CompanyID>RO10000003</cbc:CompanyID><cac:TaxScheme><cbc:ID>VAT</cbc:ID></cac:TaxScheme></cac:PartyTaxScheme>
                <cac:PartyLegalEntity><cbc:RegistrationName>Client XML Exemplu SRL</cbc:RegistrationName><cbc:CompanyID>10000003</cbc:CompanyID></cac:PartyLegalEntity>
              </cac:Party></cac:AccountingCustomerParty>
              <cac:TaxTotal><cbc:TaxAmount currencyID="RON">{$vat}.00</cbc:TaxAmount></cac:TaxTotal>
              <cac:LegalMonetaryTotal>
                <cbc:LineExtensionAmount currencyID="RON">{$net}.00</cbc:LineExtensionAmount>
                <cbc:TaxExclusiveAmount currencyID="RON">{$net}.00</cbc:TaxExclusiveAmount>
                <cbc:TaxInclusiveAmount currencyID="RON">{$total}.00</cbc:TaxInclusiveAmount>
                <cbc:PayableAmount currencyID="RON">{$total}.00</cbc:PayableAmount>
              </cac:LegalMonetaryTotal>
            {$lineXml}</{$root}>
            XML;
    }
}
