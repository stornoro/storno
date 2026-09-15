<?php

declare(strict_types=1);

namespace App\Tests\Unit\Import;

use App\Service\Import\Mapper\WooCommerceOrderMapper;
use PHPUnit\Framework\TestCase;

final class WooCommerceOrderMapperTest extends TestCase
{
    public function testOrderBecomesAnIssuedInvoiceWithLinesAndClient(): void
    {
        $mapper = new WooCommerceOrderMapper();
        self::assertSame('invoices_issued', $mapper->getImportType());

        $headers = ['Order ID', 'Order Number', 'Order Date', 'Status', 'Customer Name', 'Billing Company', 'VAT Number', 'Billing Email', 'Billing Country', 'Line items', 'Tax Total', 'Order Total', 'Currency', 'Payment Method Title'];
        $mapping = $mapper->suggestMapping($headers);
        self::assertSame('number', $mapping['Order ID']);
        self::assertSame('lineItems', $mapping['Line items']);
        self::assertSame('vatTotal', $mapping['Tax Total']);
        self::assertSame(1.0, $mapper->detectConfidence($headers));

        $row = $mapper->mapRow([
            'Order ID' => '1001', 'Order Number' => '1001', 'Order Date' => '2026-09-07 10:12:00', 'Status' => 'wc-completed',
            'Customer Name' => 'Ion Popescu', 'Billing Company' => '', 'VAT Number' => '', 'Billing Email' => 'ion.popescu@example.com', 'Billing Country' => 'RO',
            'Line items' => 'Tricou alb x 2 = 120.00 | Șapcă x 1 = 45.00', 'Tax Total' => '28.64', 'Order Total' => '165.00', 'Currency' => 'RON', 'Payment Method Title' => 'Card',
        ], $mapping);

        self::assertSame('WC-1001', $row['number']);
        self::assertSame('2026-09-07', $row['issueDate']);
        self::assertSame('issued', $row['direction']);
        self::assertTrue($row['_statusPaid']);
        self::assertTrue($row['_createClient']);
        self::assertSame('Ion Popescu', $row['receiverName']);
        self::assertSame('ion.popescu@example.com', $row['clientEmail']);
        self::assertSame('136.36', $row['subtotal']);
        self::assertSame('28.64', $row['vatTotal']);
        self::assertSame('165.00', $row['total']);
        self::assertCount(2, $row['lines']);
        self::assertSame('Tricou alb', $row['lines'][0]['description']);
        self::assertSame('2.0000', $row['lines'][0]['quantity']);
        self::assertSame('21.00', $row['lines'][0]['vatRate']);
        self::assertEqualsWithDelta(136.36, (float) $row['lines'][0]['lineTotal'] + (float) $row['lines'][1]['lineTotal'], 0.011);
        self::assertArrayNotHasKey('_vatIncludedUnknownRate', $row);

        $company = $mapper->mapRow(['Order ID' => '1002', 'Order Date' => '2026-09-08', 'Status' => 'processing', 'Customer Name' => 'Maria Ionescu', 'Billing Company' => 'Exemplu SRL', 'VAT Number' => 'RO12345678', 'Order Total' => '199.00', 'Tax Total' => '34.54'], $mapping);
        self::assertSame('Exemplu SRL', $company['receiverName']);
        self::assertSame('RO12345678', $company['receiverCif']);
        self::assertCount(1, $company['lines']);
        self::assertSame('Comandă #1002', $company['lines'][0]['description']);

        $cancelled = $mapper->mapRow(['Order ID' => '1003', 'Order Date' => '2026-09-09', 'Status' => 'cancelled', 'Customer Name' => '', 'Order Total' => '60.00'], $mapping);
        self::assertFalse($cancelled['_statusPaid']);
        self::assertSame('Persoană fizică', $cancelled['receiverName']);
        self::assertTrue($cancelled['_vatIncludedUnknownRate'], 'without a tax column the gross total is split by the company rate later');
    }

    public function testFirstAndLastNameColumns(): void
    {
        $mapper = new WooCommerceOrderMapper();
        $mapping = $mapper->suggestMapping(['Order ID', 'Order Date', 'Status', 'Order Total', 'Billing First Name', 'Billing Last Name']);
        $row = $mapper->mapRow(['Order ID' => '7', 'Order Date' => '2026-09-01', 'Status' => 'completed', 'Order Total' => '10.00', 'Billing First Name' => 'Ana', 'Billing Last Name' => 'Pop'], $mapping);
        self::assertSame('Ana Pop', $row['receiverName']);
    }
}
