<?php

declare(strict_types=1);

namespace App\Tests\Api\Import;

final class ImportPrestaShopTest extends ImportSourceTestCase
{
    public function testAdminExportWithGrossTotalsOnly(): void
    {
        $job = $this->runImport('prestashop', 'invoices_issued', 'prestashop-orders.csv');

        $this->assertSame(2, $job['createdCount']);
        $this->assertSame(1, $job['skippedCount'], 'the cancelled order is skipped');

        $invoices = array_column($this->invoicesOf($job['id']), null, 'number');
        $this->assertSame(['PS-QWERTYUIO', 'PS-XKBKNABJK'], array_keys($invoices));
        $first = $invoices['PS-XKBKNABJK'];
        $this->assertSame('165.00', $first['total']);
        $this->assertSame('I. Popescu', $first['receiver_name']);
        $this->assertNotNull($first['client_id']);

        $line = $this->linesOf('PS-XKBKNABJK', $job['id'])[0];
        $this->assertSame('Comandă #XKBKNABJK', $line['description']);
        if ($this->companyIsVatPayer()) {
            $rate = (float) $this->db()->fetchOne('SELECT rate FROM vat_rate WHERE company_id = :c AND is_default = 1 AND deleted_at IS NULL LIMIT 1', ['c' => $this->companyId]) ?: 21.0;
            $expectedNet = round(165 / (1 + $rate / 100), 2);
            $this->assertSame(number_format($expectedNet, 2, '.', ''), $first['subtotal'], 'the gross total is split with the company default rate');
            $this->assertSame(number_format(165 - $expectedNet, 2, '.', ''), $first['vat_total']);
            $this->assertSame(number_format($rate, 2, '.', ''), $line['vat_rate']);
        } else {
            $this->assertSame('165.00', $first['subtotal']);
            $this->assertSame('0.00', $first['vat_total']);
        }

        $this->revert($job['id']);
        $this->assertCount(0, $this->invoicesOf($job['id']));
    }
}
