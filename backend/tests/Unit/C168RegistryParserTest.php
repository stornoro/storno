<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\Dosar\C168RegistryParser;
use PHPUnit\Framework\TestCase;

/**
 * The registry extract is a rotated table: rows are recognised from their "INTERNT-" anchor,
 * cells from their column band, multi-line names joined, and every contract gets its state
 * after all its filings (initial, rectifying, termination). Fixture: positioned fragments of a
 * real extract with every name, address and identifier replaced.
 */
final class C168RegistryParserTest extends TestCase
{
    public function testRowsContractsAndStates(): void
    {
        $pages = json_decode((string) file_get_contents(__DIR__ . '/../Fixtures/c168-registry-items.json'), true);
        $r = (new C168RegistryParser())->parseItems($pages);

        self::assertSame('1800101400016', $r['locator']['cif']);
        self::assertSame('POPESCU I ION', $r['locator']['nume']);
        self::assertStringContainsString('Exemplu 25', $r['locator']['adresa']);
        self::assertStringNotContainsString('Incetare', $r['locator']['adresa'], 'header words must not leak into the address');

        self::assertCount(9, $r['rows']);
        $first = $r['rows'][0];
        self::assertSame('1', $first['crt']);
        self::assertSame('1210000001', $first['index']);
        self::assertSame('28.04.2026', $first['dataInregistrare']);
        self::assertSame('inregistrare', $first['operatie']);
        self::assertSame('16.04.2026', $first['dataContract']);
        self::assertSame('16.04.2027', $first['dataSfarsit']);
        self::assertSame(500.0, $first['chirie']);
        self::assertSame('EUR', $first['moneda']);
        self::assertSame('IONESCU MARIA', $first['chirias']);
        self::assertStringContainsString('Str. Exemplu', $first['adresa']);

        $termination = $r['rows'][3];
        self::assertSame('incetare', $termination['operatie']);
        self::assertSame('04.04.2026', $termination['incetare']['data']);
        self::assertSame('GEORGESCU ANA-MARIA', $termination['chirias'], 'hyphenated name split over two lines');
        self::assertSame('9', $r['rows'][8]['crt'], 'page footer totals do not leak into the last row');
        self::assertSame('EUR', $r['rows'][8]['moneda']);

        $byTenant = array_column($r['contracts'], null, 'chirias');
        self::assertSame('activ', $byTenant['IONESCU MARIA']['stare']);
        self::assertSame('incetat', $byTenant['GEORGESCU ANA-MARIA']['stare']);
        self::assertSame('04.04.2026', $byTenant['GEORGESCU ANA-MARIA']['dataIncetare']);
        self::assertSame('1210000004', $byTenant['GEORGESCU ANA-MARIA']['lastIndex'], 'the newest filing wins');
        self::assertSame('expirat', $byTenant['DUMITRESCU ELENA']['stare'], 'ended without a termination filing');
        self::assertCount(3, $byTenant['GEORGESCU ANA-MARIA']['filings']);
    }
}
