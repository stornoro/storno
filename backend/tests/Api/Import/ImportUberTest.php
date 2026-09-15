<?php

declare(strict_types=1);

namespace App\Tests\Api\Import;

final class ImportUberTest extends ImportSourceTestCase
{
    public function testWeeklyStatementBecomesSalesAndCommissionInvoices(): void
    {
        $job = $this->runImport('uber', 'platform_sales', 'uber-payments.csv', ['groupBy' => 'week']);

        // 4 rows, 3 trips in two ISO weeks → 2 sales invoices + 2 commission invoices
        $this->assertSame(4, $job['createdCount']);
        $this->assertSame(2, $job['summary']['salesInvoices']);
        $this->assertSame(2, $job['summary']['commissionInvoices']);
        $this->assertSame(4, $job['summary']['rowsAggregated']);

        $invoices = $this->invoicesOf($job['id']);
        $byNumber = array_column($invoices, null, 'number');
        $this->assertArrayHasKey('UBER-2026-W37', $byNumber);
        $this->assertArrayHasKey('UBER-COM-2026-W37', $byNumber);
        $this->assertArrayHasKey('UBER-2026-W38', $byNumber);
        $this->assertArrayHasKey('UBER-COM-2026-W38', $byNumber);

        $sales = $byNumber['UBER-2026-W37'];
        $this->assertSame('outgoing', $sales['direction']);
        $this->assertSame('Uber B.V.', $sales['receiver_name']);
        $this->assertNotNull($sales['client_id'], 'the platform becomes a client');
        $this->assertSame('78.50', $sales['total'], 'fares 71.50 + tip 5.00 + tolls 2.00, no VAT');
        $this->assertSame('0.00', $sales['vat_total']);

        $lines = $this->linesOf('UBER-2026-W37', $job['id']);
        $this->assertCount(3, $lines);
        $this->assertStringContainsString('săptămâna 37/2026', $lines[0]['description']);
        $this->assertStringContainsString('2 curse', $lines[0]['description']);
        $this->assertSame('71.50', $lines[0]['line_total']);
        $this->assertSame('5.00', $lines[1]['line_total']);
        $this->assertSame('2.00', $lines[2]['line_total']);

        $commission = $byNumber['UBER-COM-2026-W37'];
        $this->assertSame('incoming', $commission['direction']);
        $this->assertSame('Uber B.V.', $commission['sender_name']);
        $this->assertSame('17.88', $commission['total']);

        if ($this->companyIsVatPayer()) {
            $this->assertSame('services_art_278', $sales['invoice_type_code'], 'intra-community service to the NL platform');
            $this->assertSame('AE', $lines[0]['vat_category_code']);
            $this->assertSame('services_art_278', $commission['invoice_type_code']);
            $this->assertSame('AE', $this->linesOf('UBER-COM-2026-W37', $job['id'])[0]['vat_category_code']);
        } else {
            $this->assertSame('O', $lines[0]['vat_category_code']);
        }

        // Re-importing the same statement creates nothing
        $again = $this->runImport('uber', 'platform_sales', 'uber-payments.csv', ['groupBy' => 'week']);
        $this->assertSame(0, $again['createdCount']);
        $this->assertSame(4, $again['skippedCount']);
        $this->assertCount(0, $this->invoicesOf($again['id']));

        $this->revert($job['id']);
        $this->assertCount(0, $this->invoicesOf($job['id']));
        $this->assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM client WHERE import_job_id = :j', ['j' => $job['id']]));
        $this->revert($again['id']);
    }

    public function testPerTripGrouping(): void
    {
        $job = $this->runImport('uber', 'platform_sales', 'uber-payments.csv', ['groupBy' => 'trip']);
        $numbers = array_column($this->invoicesOf($job['id']), 'number');
        $this->assertContains('UBER-t-0001', $numbers);
        $this->assertContains('UBER-COM-t-0001', $numbers);
        $this->assertCount(6, $numbers, 'three trips, each with a sales and a commission invoice');
        $totals = array_column($this->invoicesOf($job['id']), 'total', 'number');
        $this->assertSame('53.50', $totals['UBER-t-0001'], 'the fare row and the tip row of the trip are summed');
        $this->assertSame('12.13', $totals['UBER-COM-t-0001'], 'the service fee of the trip is the commission invoice');
        $this->revert($job['id']);
    }

    public function testSourceTemplateUsesThePlatformColumns(): void
    {
        $this->client->request('GET', '/api/v1/import/template?importType=platform_sales&source=uber', [], [], $this->buildHeaders($this->headers));
        $this->assertResponseStatusCodeSame(200);
        $csv = (string) $this->client->getInternalResponse()->getContent();
        $this->assertStringContainsString('Trip ID', $csv);
        $this->assertStringContainsString('Service fee', $csv);
    }
}
