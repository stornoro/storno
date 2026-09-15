<?php

declare(strict_types=1);

namespace App\Tests\Api\Import;

final class ImportWooCommerceTest extends ImportSourceTestCase
{
    public function testOrdersBecomeInvoicesAndClients(): void
    {
        $job = $this->runImport('woocommerce', 'invoices_issued', 'woocommerce-orders.csv');

        $this->assertSame(2, $job['createdCount'], 'the cancelled order is skipped');
        $this->assertSame(1, $job['skippedCount']);

        $invoices = array_column($this->invoicesOf($job['id']), null, 'number');
        $this->assertSame(['WC-1001', 'WC-1002'], array_keys($invoices));
        $this->assertSame('165.00', $invoices['WC-1001']['total']);
        $this->assertSame('28.64', $invoices['WC-1001']['vat_total']);
        $this->assertSame('Ion Popescu', $invoices['WC-1001']['receiver_name']);
        $this->assertNotNull($invoices['WC-1001']['client_id']);

        $lines = $this->linesOf('WC-1001', $job['id']);
        $this->assertCount(2, $lines);
        $this->assertSame('Tricou alb', $lines[0]['description']);
        $this->assertSame('2.0000', $lines[0]['quantity']);
        $this->assertSame('Șapcă', $lines[1]['description']);

        $company = $invoices['WC-1002'];
        $this->assertSame('Exemplu SRL', $company['receiver_name']);
        $client = $this->db()->fetchAssociative('SELECT name, cui, vat_code, type, email, import_job_id FROM client WHERE id = :id', ['id' => $company['client_id']]);
        $this->assertSame('12345678', $client['cui']);
        $this->assertSame('RO12345678', $client['vat_code']);
        $this->assertSame('company', $client['type']);
        $this->assertSame('contact@example.com', $client['email']);
        $this->assertSame($job['id'], $client['import_job_id'], 'created clients are reverted with the import');

        // includeAll imports the cancelled order too; the two paid ones are already there
        $all = $this->runImport('woocommerce', 'invoices_issued', 'woocommerce-orders.csv', ['includeAll' => true]);
        $this->assertSame(1, $all['createdCount']);
        $this->assertSame(2, $all['skippedCount']);
        $this->assertSame(['WC-1003'], array_keys(array_column($this->invoicesOf($all['id']), null, 'number')));

        $this->revert($all['id']);
        $this->revert($job['id']);
        $this->assertCount(0, $this->invoicesOf($job['id']));
        $this->assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM client WHERE import_job_id = :j', ['j' => $job['id']]));
    }
}
