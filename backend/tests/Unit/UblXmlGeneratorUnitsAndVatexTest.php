<?php

namespace App\Tests\Unit;

use App\Entity\Invoice;
use App\Enum\InvoiceTypeCode;
use App\Service\Anaf\UblXmlGenerator;
use App\Service\ExchangeRateService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\FilterCollection;
use PHPUnit\Framework\TestCase;

class UblXmlGeneratorUnitsAndVatexTest extends TestCase
{
    private UblXmlGenerator $generator;

    protected function setUp(): void
    {
        $filters = $this->createMock(FilterCollection::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getFilters')->willReturn($filters);
        $this->generator = new UblXmlGenerator($this->createMock(ExchangeRateService::class), $em);
    }

    private function call(string $method, array $args): mixed
    {
        $ref = new \ReflectionMethod(UblXmlGenerator::class, $method);
        return $ref->invoke($this->generator, ...$args);
    }

    /** @dataProvider unitProvider */
    public function testRomanianLabelsMapToUnEceCodes(string $label, string $code): void
    {
        self::assertSame($code, $this->call('mapUnitOfMeasure', [$label]));
    }

    public static function unitProvider(): iterable
    {
        yield ['buc', 'H87'];
        yield ['Bucati', 'H87'];
        yield ['kg', 'KGM'];
        yield ['pachet', 'XPK']; // PK is not a UN/ECE code
        yield ['cutie', 'XBX'];
        yield ['mp', 'MTK'];
        yield ['mc', 'MTQ'];
        yield ['t', 'TNE'];
        yield ['kwh', 'KWH'];
        yield ['serv', 'E48'];
        yield ['%', 'P1'];
        yield ['pereche', 'PR'];
        yield ['XPK', 'XPK']; // already a code
        yield ['PK', 'XPK']; // legacy value stored by older versions
        yield ['C62', 'H87'];
        yield ['unknown-unit', 'H87'];
    }

    /** @dataProvider reverseProvider */
    public function testUnEceCodesMapBackToLabels(string $code, string $label): void
    {
        self::assertSame($label, UblXmlGenerator::reverseMapUnitOfMeasure($code));
    }

    public static function reverseProvider(): iterable
    {
        yield ['H87', 'buc'];
        yield ['XPK', 'pachet'];
        yield ['PK', 'pachet'];
        yield ['XBX', 'cutie'];
        yield ['E48', 'serv'];
        yield ['KWH', 'kwh'];
        yield ['ZZZ', 'buc'];
    }

    public function testEveryDefaultUnitRoundTrips(): void
    {
        foreach (UblXmlGenerator::UNIT_CODES as $label => $code) {
            $back = UblXmlGenerator::reverseMapUnitOfMeasure($code);
            self::assertSame($code, $this->call('mapUnitOfMeasure', [$back]), "code $code round-trips through label $back");
        }
    }

    public function testVatexDependsOnSpecialRegime(): void
    {
        $plain = new Invoice();
        self::assertSame('VATEX-EU-132', $this->call('getVatexCode', ['E', $plain]));
        self::assertSame('Scutit de TVA', $this->call('getVatExemptionReason', ['E', $plain]));

        $travel = (new Invoice())->setInvoiceTypeCode(InvoiceTypeCode::SERVICES_ART_311->value);
        self::assertSame('VATEX-EU-309', $this->call('getVatexCode', ['E', $travel]));
        self::assertStringContainsString('311', $this->call('getVatExemptionReason', ['E', $travel]));

        $margin = (new Invoice())->setInvoiceTypeCode(InvoiceTypeCode::SALES_ART_312->value);
        self::assertSame('VATEX-EU-F', $this->call('getVatexCode', ['E', $margin]));

        // Other categories are unaffected by the regime
        self::assertSame('VATEX-EU-AE', $this->call('getVatexCode', ['AE', $margin]));
        self::assertSame('VATEX-EU-O', $this->call('getVatexCode', ['O', null]));
        self::assertNull($this->call('getVatexCode', ['S', $margin]));
    }
}
