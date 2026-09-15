<?php

declare(strict_types=1);

namespace App\Tests\Unit\Import;

use App\Service\Import\Mapper\PrestaShopOrderMapper;
use PHPUnit\Framework\TestCase;

final class PrestaShopOrderMapperTest extends TestCase
{
    public function testAdminExportColumns(): void
    {
        $mapper = new PrestaShopOrderMapper();
        $headers = ['ID', 'Reference', 'New client', 'Delivery', 'Customer', 'Total', 'Payment', 'Status', 'Date'];
        $mapping = $mapper->suggestMapping($headers);
        self::assertSame(['ID' => 'number', 'Reference' => 'orderReference', 'Delivery' => 'clientCountry', 'Customer' => 'receiverName', 'Total' => 'total', 'Payment' => 'paymentMethod', 'Status' => 'status', 'Date' => 'issueDate'], $mapping);
        self::assertSame(1.0, $mapper->detectConfidence($headers));

        $row = $mapper->mapRow(['ID' => '501', 'Reference' => 'XKBKNABJK', 'New client' => 'Yes', 'Delivery' => 'România', 'Customer' => 'I. Popescu', 'Total' => '165,00 lei', 'Payment' => 'Card', 'Status' => 'Livrat', 'Date' => '2026-09-07 10:12:00'], $mapping);
        self::assertSame('PS-XKBKNABJK', $row['number']);
        self::assertSame('XKBKNABJK', $row['orderNumber']);
        self::assertSame('165.00', $row['total']);
        self::assertTrue($row['_statusPaid']);
        self::assertTrue($row['_vatIncludedUnknownRate']);
        self::assertSame('Comandă #XKBKNABJK', $row['lines'][0]['description']);
        self::assertSame('Card', $row['paymentMethod']);

        $cancelled = $mapper->mapRow(['ID' => '503', 'Reference' => 'ZXCVBNMAS', 'Customer' => 'V. Georgescu', 'Total' => '€60.00', 'Status' => 'Anulat', 'Date' => '2026-09-09 09:00:00'], $mapping);
        self::assertFalse($cancelled['_statusPaid']);
        self::assertSame('60.00', $cancelled['total']);

        $english = $mapper->mapRow(['ID' => '504', 'Reference' => 'AAA', 'Customer' => 'J. Doe', 'Total' => '1,234.50', 'Status' => 'Payment accepted', 'Date' => '09/09/2026'], $mapping);
        self::assertTrue($english['_statusPaid']);
        self::assertSame('1234.50', $english['total']);
        self::assertSame('2026-09-09', $english['issueDate']);
    }
}
