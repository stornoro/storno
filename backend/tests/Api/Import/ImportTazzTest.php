<?php

declare(strict_types=1);

namespace App\Tests\Api\Import;

final class ImportTazzTest extends ImportSourceTestCase
{
    public function testRomanianPlatformKeepsTheVatOfTheExport(): void
    {
        $job = $this->runImport('tazz', 'platform_sales', 'tazz-comenzi.csv', ['groupBy' => 'day']);

        $invoices = array_column($this->invoicesOf($job['id']), null, 'number');
        $this->assertArrayHasKey('TAZZ-2026-09-07', $invoices);
        $this->assertArrayHasKey('TAZZ-COM-2026-09-07', $invoices);
        $this->assertArrayHasKey('TAZZ-2026-09-08', $invoices);

        $sale = $invoices['TAZZ-2026-09-07'];
        $this->assertSame('76.00', $sale['total']);
        $this->assertSame('Tazz', $sale['receiver_name']);
        $this->assertNull($sale['invoice_type_code'], 'a Romanian platform: no reverse charge');
        $commission = $invoices['TAZZ-COM-2026-09-07'];
        if ($this->companyIsVatPayer()) {
            $this->assertSame('7.67', $sale['vat_total']);
            $this->assertSame('68.33', $sale['subtotal']);
            $line = $this->linesOf('TAZZ-2026-09-07', $job['id'])[0];
            $this->assertSame('11.00', $line['vat_rate'], '7.67 on 68.33 is the 11 % rate');
            $this->assertSame('S', $line['vat_category_code']);
            $this->assertSame('18.39', $commission['total'], '15.20 + 3.19 VAT');
            $this->assertSame('3.19', $commission['vat_total']);
        } else {
            $this->assertSame('0.00', $sale['vat_total']);
            $this->assertSame('18.39', $commission['total']);
        }

        $sale2 = $invoices['TAZZ-2026-09-08'];
        $this->assertSame('125.50', $sale2['total'], '120.50 order + 5.00 tip');

        $this->revert($job['id']);
    }
}
