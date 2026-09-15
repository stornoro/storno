<?php

declare(strict_types=1);

namespace App\Tests\Unit\Import;

use App\Service\Import\Mapper\BoltInvoiceMapper;
use App\Service\Import\Mapper\BoltStatementMapper;
use PHPUnit\Framework\TestCase;

final class BoltStatementMapperTest extends TestCase
{
    public function testEarningsReportWithRomanianDecimals(): void
    {
        $mapper = new BoltStatementMapper();
        $headers = ['Ride ID', 'Date', 'Driver', 'Ride type', 'Gross earnings', 'Tip', 'Toll', 'VAT', 'Bolt commission', 'Commission VAT', 'Net earnings', 'Currency', 'Status'];
        $mapping = $mapper->suggestMapping($headers);
        self::assertSame('gross', $mapping['Gross earnings']);
        self::assertSame('commission', $mapping['Bolt commission']);
        self::assertSame('payout', $mapping['Net earnings']);
        self::assertSame(1.0, $mapper->detectConfidence($headers));

        $row = $mapper->mapRow(['Ride ID' => 'BR-000002', 'Date' => '07.09.2026 21:30', 'Gross earnings' => '52,40', 'Tip' => '4,00', 'Bolt commission' => '10,48', 'Net earnings' => '45,92', 'Currency' => 'RON'], $mapping);
        self::assertSame('2026-09-07', $row['date']);
        self::assertSame('52.40', $row['gross']);
        self::assertSame('4.00', $row['tips']);
        self::assertSame('10.48', $row['commission']);
        self::assertSame('EE', $row['platformDefaults']['country']);
    }

    public function testReceivedInvoiceMapperDerivesTheVatRateFromTheAmounts(): void
    {
        $mapper = new BoltInvoiceMapper();
        $mapping = $mapper->getDefaultMapping();

        $reverseCharge = $mapper->mapRow(['Factura numărul' => 'B-1', 'Data' => '2026-09-07', 'Valoare (fără TVA)' => '100,00', 'TVA' => '0,00', 'Valoare totală' => '100,00'], $mapping);
        self::assertSame('0', $reverseCharge['lines'][0]['vatRate']);
        self::assertSame('AE', $reverseCharge['lines'][0]['vatCategoryCode']);

        $domestic = $mapper->mapRow(['Factura numărul' => 'B-2', 'Data' => '2026-09-07', 'Valoare (fără TVA)' => '100,00', 'TVA' => '21,00', 'Valoare totală' => '121,00'], $mapping);
        self::assertSame('21', $domestic['lines'][0]['vatRate']);
        self::assertSame('S', $domestic['lines'][0]['vatCategoryCode']);
    }
}
