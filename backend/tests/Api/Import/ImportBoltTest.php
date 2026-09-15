<?php

declare(strict_types=1);

namespace App\Tests\Api\Import;

final class ImportBoltTest extends ImportSourceTestCase
{
    public function testEarningsReportGroupedByDay(): void
    {
        $job = $this->runImport('bolt', 'platform_sales', 'bolt-earnings.csv', ['groupBy' => 'day']);

        $invoices = array_column($this->invoicesOf($job['id']), null, 'number');
        $this->assertArrayHasKey('BOLT-2026-09-07', $invoices);
        $this->assertArrayHasKey('BOLT-COM-2026-09-07', $invoices);
        $this->assertArrayHasKey('BOLT-2026-09-09', $invoices);
        $this->assertArrayHasKey('BOLT-COM-2026-09-09', $invoices);
        $this->assertSame('91.40', $invoices['BOLT-2026-09-07']['total'], '35.00 + 52.40 rides + 4.00 tip');
        $this->assertSame('17.48', $invoices['BOLT-COM-2026-09-07']['total']);
        $this->assertSame('Bolt Operations OÜ', $invoices['BOLT-2026-09-07']['receiver_name']);

        // A source override of the platform identity lands on the documents and the client
        $this->revert($job['id']);
        $job = $this->runImport('bolt', 'platform_sales', 'bolt-earnings.csv', ['groupBy' => 'week', 'platformName' => 'Platforma Test OÜ', 'platformCif' => 'EE000000000', 'platformCountry' => 'EE']);
        $invoices = array_column($this->invoicesOf($job['id']), null, 'number');
        $this->assertArrayHasKey('BOLT-2026-W37', $invoices);
        $this->assertSame('Platforma Test OÜ', $invoices['BOLT-2026-W37']['receiver_name']);
        $client = $this->db()->fetchAssociative('SELECT name, cui, country FROM client WHERE id = :id', ['id' => $invoices['BOLT-2026-W37']['client_id']]);
        $this->assertSame('EE000000000', $client['cui']);
        $this->assertSame('EE', $client['country']);
        $this->revert($job['id']);
    }
}
