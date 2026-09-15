<?php

declare(strict_types=1);

namespace App\Tests\Unit\Import;

use App\Service\Import\Mapper\GlovoStatementMapper;
use PHPUnit\Framework\TestCase;

final class GlovoStatementMapperTest extends TestCase
{
    public function testOrdersExport(): void
    {
        $mapper = new GlovoStatementMapper();
        $headers = ['Order ID', 'Order date', 'Customer', 'Order type', 'Order total', 'Tips', 'Delivery fee', 'VAT', 'Commission', 'Commission VAT', 'Payout', 'Currency', 'Status'];
        $mapping = $mapper->suggestMapping($headers);
        self::assertSame('externalId', $mapping['Order ID']);
        self::assertSame('gross', $mapping['Order total']);
        self::assertSame('vat', $mapping['VAT']);
        self::assertSame('commissionVat', $mapping['Commission VAT']);
        self::assertSame(1.0, $mapper->detectConfidence($headers));

        $row = $mapper->mapRow(['Order ID' => 'GLV-100001', 'Order date' => '2026-09-07 12:31', 'Order total' => '89.00', 'VAT' => '8.98', 'Commission' => '26.70', 'Commission VAT' => '5.61', 'Payout' => '56.69', 'Currency' => 'RON', 'Status' => 'Delivered'], $mapping);
        self::assertSame('2026-09-07', $row['date']);
        self::assertSame('89.00', $row['gross']);
        self::assertSame('8.98', $row['vat']);
        self::assertSame('26.70', $row['commission']);
        self::assertSame('5.61', $row['commissionVat']);
        self::assertSame('ES', $row['platformDefaults']['country']);
        self::assertSame(['date', 'gross'], $mapper->getRequiredFields());
    }
}
