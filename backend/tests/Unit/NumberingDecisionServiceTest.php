<?php

namespace App\Tests\Unit;

use App\Entity\Company;
use App\Entity\DocumentSeries;
use App\Repository\DocumentSeriesRepository;
use App\Service\DocumentSeries\NumberingDecisionService;
use PHPUnit\Framework\TestCase;

class NumberingDecisionServiceTest extends TestCase
{
    private Company $company;

    protected function setUp(): void
    {
        $this->company = new Company();
        $this->company->setName('Firma Exemplu SRL');
        $this->company->setCif(12345678);
        $this->company->setVatCode('RO12345678');
        $this->company->setRegistrationNumber('J40/1234/2020');
        $this->company->setAddress('Str. Exemplu nr. 1');
        $this->company->setCity('București');
        $this->company->setCountry('RO');
        $this->company->setRepresentative('Ion Popescu');
        $this->company->setRepresentativeRole('Administrator');
    }

    private function series(string $prefix, string $type, int $current = 0, bool $default = false, bool $active = true): DocumentSeries
    {
        return (new DocumentSeries())
            ->setCompany($this->company)
            ->setPrefix($prefix)
            ->setType($type)
            ->setCurrentNumber($current)
            ->setIsDefault($default)
            ->setActive($active);
    }

    /**
     * @param DocumentSeries[] $series
     * @param array<string, int[]> $issued prefix => numbers issued in the year
     */
    private function service(array $series, array $issued = []): NumberingDecisionService
    {
        $repo = $this->createMock(DocumentSeriesRepository::class);
        $repo->method('findByCompany')->willReturn($series);
        $repo->method('findIssuedNumbersForYear')
            ->willReturnCallback(fn (DocumentSeries $s) => $issued[$s->getPrefix()] ?? []);

        return new NumberingDecisionService($repo);
    }

    public function testRowsUseDefaultRangeAndCompanyIdentification(): void
    {
        $service = $this->service([
            $this->series('PRO', 'proforma'),
            $this->series('FAC', 'invoice', 0, true),
            $this->series('CH', 'receipt', 120),
        ]);

        $decision = $service->build($this->company, 2026);

        self::assertSame(2026, $decision['year']);
        self::assertSame(1, $decision['decisionNumber']);
        self::assertSame('2026-01-01', $decision['decisionDate']);
        self::assertSame('Ion Popescu', $decision['responsible']);
        self::assertSame(9999, $decision['rangeSize']);
        self::assertSame('Firma Exemplu SRL', $decision['company']['name']);
        self::assertSame('RO12345678', $decision['company']['vatCode']);
        self::assertSame('J40/1234/2020', $decision['company']['registrationNumber']);
        self::assertSame('Str. Exemplu nr. 1, București', $decision['company']['address']);
        self::assertSame('Ion Popescu', $decision['company']['representative']);
        self::assertSame([], $decision['warnings']);

        // Ordered by document type (invoice first), then prefix.
        self::assertSame(['FAC', 'PRO', 'CH'], array_column($decision['rows'], 'prefix'));

        $fac = $decision['rows'][0];
        self::assertSame('Factură', $fac['typeLabel']);
        self::assertSame(1, $fac['firstNumber']);
        self::assertSame(9999, $fac['lastNumber']);
        self::assertSame('FAC0001', $fac['firstFormatted']);
        self::assertSame('FAC9999', $fac['lastFormatted']);
        self::assertSame('FAC0001', $fac['formatExample']);
        self::assertTrue($fac['isDefault']);

        // A series that already counted to 120 continues from 121.
        $ch = $decision['rows'][2];
        self::assertSame('Chitanță', $ch['typeLabel']);
        self::assertSame(121, $ch['firstNumber']);
        self::assertSame(121 + 9999 - 1, $ch['lastNumber']);
        self::assertSame('CH0121', $ch['firstFormatted']);
    }

    public function testIssuedNumbersInTheYearDefineTheFirstNumberAndExtendTheRange(): void
    {
        $service = $this->service(
            [$this->series('FAC', 'invoice', 250, true), $this->series('AVZ', 'delivery_note', 30)],
            ['FAC' => [201, 202, 250], 'AVZ' => [12, 30]],
        );

        $decision = $service->build($this->company, 2026, ['rangeSize' => 20]);

        $fac = $decision['rows'][0];
        self::assertSame(201, $fac['firstNumber']);
        // planned 201 + 20 - 1 = 220 < max issued 250 → the range is extended to the max issued number
        self::assertSame(250, $fac['lastNumber']);
        self::assertSame(3, $fac['issuedCount']);

        $avz = $decision['rows'][1];
        self::assertSame(12, $avz['firstNumber']);
        self::assertSame(31, $avz['lastNumber']);
    }

    public function testInactiveSeriesAreListedOnlyWhenTheyIssuedNumbersInTheYear(): void
    {
        $service = $this->service(
            [$this->series('OLD', 'invoice', 50, false, false), $this->series('NEW', 'invoice', 0, true), $this->series('ZZZ', 'invoice', 5, false, false)],
            ['OLD' => [48, 49, 50]],
        );

        $rows = $service->build($this->company, 2026)['rows'];

        self::assertSame(['NEW', 'OLD'], array_column($rows, 'prefix'));
        self::assertFalse($rows[1]['active']);
    }

    public function testOptionsOverrideDefaults(): void
    {
        $service = $this->service([$this->series('FAC', 'invoice')]);

        $decision = $service->build($this->company, 2026, [
            'decisionNumber' => '7',
            'decisionDate' => '2026-01-05',
            'responsible' => '  Maria Ionescu ',
            'rangeSize' => '500',
            'rangeSizes' => ['FAC' => 100],
        ]);

        self::assertSame(7, $decision['decisionNumber']);
        self::assertSame('2026-01-05', $decision['decisionDate']);
        self::assertSame('Maria Ionescu', $decision['responsible']);
        self::assertSame(500, $decision['rangeSize']);
        self::assertSame(100, $decision['rows'][0]['lastNumber']);
    }

    public function testLegalBasisByYear(): void
    {
        $service = $this->service([$this->series('FAC', 'invoice')]);

        self::assertSame('OMFP 2634/2015', $service->build($this->company, 2016)['legalBasis']['code']);
        self::assertSame('OMFP 2634/2015', $service->build($this->company, 2026)['legalBasis']['code']);
        self::assertSame('OMEF 2226/2006', $service->build($this->company, 2015)['legalBasis']['code']);
        self::assertStringContainsString('2634/2015', NumberingDecisionService::legalBasis(2026)['text']);
        self::assertStringContainsString('contabilității nr. 82/1991', NumberingDecisionService::legalBasis(2026)['text']);
    }

    public function testWarningsWhenNoSeriesRepresentativeOrRegistrationNumber(): void
    {
        $this->company->setRepresentative(null);
        $this->company->setRegistrationNumber(null);
        $decision = $this->service([])->build($this->company, 2026);

        self::assertNull($decision['responsible']);
        self::assertSame(['NO_SERIES', 'NO_RESPONSIBLE', 'NO_REGISTRATION_NUMBER'], array_column($decision['warnings'], 'code'));
    }

    /**
     * @dataProvider invalidOptions
     */
    public function testInvalidOptionsAreRejected(int $year, array $options): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service([$this->series('FAC', 'invoice')])->build($this->company, $year, $options);
    }

    public static function invalidOptions(): iterable
    {
        yield 'year too small' => [1999, []];
        yield 'bad date' => [2026, ['decisionDate' => '2026-13-01']];
        yield 'date format' => [2026, ['decisionDate' => '01.01.2026']];
        yield 'zero range' => [2026, ['rangeSize' => 0]];
        yield 'non-numeric number' => [2026, ['decisionNumber' => 'abc']];
        yield 'responsible too long' => [2026, ['responsible' => str_repeat('a', 201)]];
    }
}
