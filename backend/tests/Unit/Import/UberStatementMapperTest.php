<?php

declare(strict_types=1);

namespace App\Tests\Unit\Import;

use App\Service\Import\Mapper\UberStatementMapper;
use PHPUnit\Framework\TestCase;

final class UberStatementMapperTest extends TestCase
{
    public function testSuggestsMappingFromAliasesAndNormalisesRows(): void
    {
        $mapper = new UberStatementMapper();
        self::assertSame('platform_sales', $mapper->getImportType());

        $headers = ['Trip ID', 'Trip date', 'Driver', 'Event type', 'Fare', 'Tips', 'Tolls', 'Taxes', 'Service fee', 'Total', 'Currency', 'Status'];
        $mapping = $mapper->suggestMapping($headers);
        self::assertSame('externalId', $mapping['Trip ID']);
        self::assertSame('date', $mapping['Trip date']);
        self::assertSame('gross', $mapping['Fare']);
        self::assertSame('commission', $mapping['Service fee']);
        self::assertSame('payout', $mapping['Total']);
        self::assertSame(1.0, $mapper->detectConfidence($headers));
        self::assertLessThan(0.5, $mapper->detectConfidence(['Factura numărul', 'Valoare totală']));

        // Romanian export with translated headers
        $ro = $mapper->suggestMapping(['ID cursă', 'Data cursei', 'Tarif', 'Bacșiș', 'Comision Uber', 'Sumă netă']);
        self::assertSame(['ID cursă' => 'externalId', 'Data cursei' => 'date', 'Tarif' => 'gross', 'Bacșiș' => 'tips', 'Comision Uber' => 'commission', 'Sumă netă' => 'payout'], $ro);

        $row = $mapper->mapRow([
            'Trip ID' => 't-0001', 'Trip date' => '2026-09-07 08:15:00', 'Driver' => 'Popescu Ion', 'Event type' => 'Trip',
            'Fare' => '48.50', 'Tips' => '', 'Tolls' => '0.00', 'Taxes' => '0.00', 'Service fee' => '-12.13', 'Total' => '36.37', 'Currency' => 'ron', 'Status' => 'completed',
        ], $mapping);

        self::assertSame('t-0001', $row['externalId']);
        self::assertSame('2026-09-07', $row['date']);
        self::assertSame('48.50', $row['gross']);
        self::assertSame('12.13', $row['commission'], 'the negative fee becomes a positive commission');
        self::assertArrayNotHasKey('tips', $row, 'empty cells are left out');
        self::assertSame('RON', $row['currency']);
        self::assertSame('uber', $row['platform']);
        self::assertSame('NL', $row['platformDefaults']['country']);
    }

    public function testTemplateRowsMatchTheDefaultMapping(): void
    {
        $mapper = new UberStatementMapper();
        $headers = array_keys($mapper->getDefaultMapping());
        foreach ($mapper->getTemplateRows() as $row) {
            self::assertSame([], array_diff(array_keys($row), $headers));
        }
        self::assertContains('Trip ID', $headers);
    }
}
