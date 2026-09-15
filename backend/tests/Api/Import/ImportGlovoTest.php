<?php

declare(strict_types=1);

namespace App\Tests\Api\Import;

final class ImportGlovoTest extends ImportSourceTestCase
{
    public function testOrdersGroupedByDayWithCommissionFromTheSpanishEntity(): void
    {
        $job = $this->runImport('glovo', 'platform_sales', 'glovo-orders.csv', ['groupBy' => 'day']);

        $invoices = array_column($this->invoicesOf($job['id']), null, 'number');
        $this->assertSame(['GLOVO-2026-09-07', 'GLOVO-2026-09-08', 'GLOVO-COM-2026-09-07', 'GLOVO-COM-2026-09-08'], array_keys($invoices));
        $this->assertSame('136.50', $invoices['GLOVO-2026-09-07']['total'], '89.00 + 45.50 orders + 2.00 tip');
        $this->assertSame('Glovoapp23 S.L.', $invoices['GLOVO-2026-09-07']['receiver_name']);

        $commission = $invoices['GLOVO-COM-2026-09-07'];
        if ($this->companyIsVatPayer()) {
            $this->assertSame('40.35', $commission['total'], 'reverse charge: the commission without the VAT of the export');
            $this->assertSame('services_art_278', $commission['invoice_type_code']);
        } else {
            $this->assertSame('48.83', $commission['total'], 'not a VAT payer: the commission with its VAT is the expense');
        }

        $this->revert($job['id']);
        $this->assertCount(0, $this->invoicesOf($job['id']));
    }

    public function testPerOrder(): void
    {
        $job = $this->runImport('glovo', 'platform_sales', 'glovo-orders.csv', ['groupBy' => 'order']);
        $numbers = array_keys(array_column($this->invoicesOf($job['id']), null, 'number'));
        $this->assertContains('GLOVO-GLV-100003', $numbers);
        $this->assertContains('GLOVO-COM-GLV-100003', $numbers);
        $this->assertCount(6, $numbers);
        $this->revert($job['id']);
    }
}
