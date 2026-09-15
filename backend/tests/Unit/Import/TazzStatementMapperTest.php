<?php

declare(strict_types=1);

namespace App\Tests\Unit\Import;

use App\Service\Import\Mapper\TazzStatementMapper;
use PHPUnit\Framework\TestCase;

final class TazzStatementMapperTest extends TestCase
{
    public function testRomanianExportHeadersAndAmounts(): void
    {
        $mapper = new TazzStatementMapper();
        $headers = ['Nr. comandă', 'Data', 'Client', 'Tip comandă', 'Total comandă', 'Bacșiș', 'Taxă livrare', 'TVA', 'Comision', 'TVA comision', 'De încasat', 'Monedă', 'Status'];
        $mapping = $mapper->suggestMapping($headers);
        self::assertSame('externalId', $mapping['Nr. comandă']);
        self::assertSame('gross', $mapping['Total comandă']);
        self::assertSame('commission', $mapping['Comision']);
        self::assertSame('commissionVat', $mapping['TVA comision']);
        self::assertSame('payout', $mapping['De încasat']);

        // headers without diacritics map the same way
        $plain = $mapper->suggestMapping(['Nr comanda', 'Data', 'Total comanda', 'Comision', 'De incasat']);
        self::assertSame('externalId', $plain['Nr comanda']);
        self::assertSame('payout', $plain['De incasat']);

        $row = $mapper->mapRow(['Nr. comandă' => 'TZ-500002', 'Data' => '08.09.2026 20:45', 'Total comandă' => '120,50', 'Bacșiș' => '5,00', 'TVA' => '12,16', 'Comision' => '24,10', 'TVA comision' => '5,06', 'De încasat' => '96,34', 'Monedă' => 'RON'], $mapping);
        self::assertSame('2026-09-08', $row['date']);
        self::assertSame('120.50', $row['gross']);
        self::assertSame('5.00', $row['tips']);
        self::assertSame('24.10', $row['commission']);
        self::assertSame('RO', $row['platformDefaults']['country']);
    }
}
