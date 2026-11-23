<?php

namespace App\Tests\Unit;

use App\Entity\Company;
use App\Enum\DocumentStatus;
use App\Repository\InvoiceRepository;
use App\Service\ExchangeRateService;
use App\Service\Report\VatReportService;
use PHPUnit\Framework\TestCase;

/** The VAT summary over an arbitrary period with the direction / rate / status filters. */
class VatReportServiceFiltersTest extends TestCase
{
    private array $lastFilters = [];
    private ?string $lastVatRate = null;

    /**
     * The repository sums in SQL (covered against the database in InvoiceFilteredQueryTest).
     * Here it returns a fixed summary, so these tests cover what the service still does: the
     * filters it passes down and the report it assembles from the sums.
     */
    private function service(array $summary): VatReportService
    {
        $repo = $this->createMock(InvoiceRepository::class);
        $repo->method('summarizeVatByCompanyFiltered')->willReturnCallback(function (Company $c, array $filters, ?string $vatRate) use ($summary) {
            $this->lastFilters = $filters;
            $this->lastVatRate = $vatRate;

            return $summary;
        });

        return new VatReportService($repo, $this->createMock(ExchangeRateService::class));
    }

    /** Two outgoing invoices (one a draft) in RON and one incoming in EUR. */
    private function summary(): array
    {
        return [
            'outgoing' => [
                'rates' => ['21.00' => ['taxableBase' => '110.00', 'vatAmount' => '23.10'], '9' => ['taxableBase' => '50.00', 'vatAmount' => '4.50']],
                'totals' => ['taxableBase' => '160.00', 'vatAmount' => '27.60', 'total' => '187.60', 'count' => 2],
                'currencies' => ['RON'],
            ],
            'incoming' => [
                'rates' => ['21.00' => ['taxableBase' => '200.00', 'vatAmount' => '42.00']],
                'totals' => ['taxableBase' => '200.00', 'vatAmount' => '42.00', 'total' => '242.00', 'count' => 1],
                'currencies' => ['eur', null],
            ],
        ];
    }

    public function testPeriodAndRepositoryFiltersArePassedThrough(): void
    {
        $report = $this->service($this->summary())->generateFor(new Company(), [
            'from' => new \DateTimeImmutable('2026-07-01'),
            'to' => new \DateTimeImmutable('2026-09-30'),
            'period' => '2026-Q3',
            'direction' => 'outgoing',
            'clientId' => 'c-1',
            'currency' => 'RON',
            'documentType' => 'invoice',
        ]);

        self::assertSame('2026-Q3', $report['period']);
        self::assertSame('2026-07-01', $report['from']);
        self::assertSame('2026-09-30', $report['to']);
        self::assertSame('2026-07-01', $this->lastFilters['dateFrom']);
        self::assertSame('2026-09-30 23:59:59', $this->lastFilters['dateTo']);
        self::assertSame('outgoing', $this->lastFilters['direction']);
        self::assertSame('c-1', $this->lastFilters['clientId']);
        self::assertSame('RON', $this->lastFilters['currency']);
        self::assertSame('invoice', $this->lastFilters['type']);
        self::assertTrue($this->lastFilters['excludeCancelled']);
        self::assertArrayNotHasKey('excludeStatuses', $this->lastFilters);
        self::assertNull($this->lastVatRate);
        self::assertSame('outgoing', $report['filters']['direction']);
    }

    public function testReportIsAssembledFromTheSums(): void
    {
        $report = $this->service($this->summary())->generateFor(new Company(), ['from' => new \DateTimeImmutable('2026-09-01'), 'to' => new \DateTimeImmutable('2026-09-30')]);

        self::assertSame('2026-09-01..2026-09-30', $report['period']);
        self::assertSame('160.00', $report['totals']['outgoing']['taxableBase']);
        self::assertSame('27.60', $report['totals']['outgoing']['vatAmount']);
        self::assertSame('110.00', $report['outgoing']['21.00']['taxableBase']);
        self::assertSame('23.10', $report['outgoing']['21.00']['vatAmount']);
        self::assertSame('4.50', $report['outgoing']['9.00']['vatAmount']);
        self::assertSame('0.00', $report['outgoing']['5.00']['vatAmount']);
        self::assertSame('42.00', $report['totals']['incoming']['vatAmount']);
        self::assertSame('-14.40', $report['netVat']);
        self::assertSame(['outgoing' => 2, 'incoming' => 1], $report['invoiceCount']);
        self::assertSame(['EUR', 'RON'], $report['currencies']);
    }

    public function testFinalOnlyExcludesDraftsInTheQuery(): void
    {
        $report = $this->service($this->summary())->generateFor(new Company(), ['from' => new \DateTimeImmutable('2026-09-01'), 'to' => new \DateTimeImmutable('2026-09-30'), 'finalOnly' => true]);

        self::assertSame([DocumentStatus::DRAFT, DocumentStatus::REJECTED, DocumentStatus::CONVERTED], $this->lastFilters['excludeStatuses']);
        self::assertTrue($report['filters']['finalOnly']);
    }

    public function testVatRateFilterIsNormalizedAndPassedDown(): void
    {
        $report = $this->service([])->generateFor(new Company(), ['from' => new \DateTimeImmutable('2026-09-01'), 'to' => new \DateTimeImmutable('2026-09-30'), 'vatRate' => '9']);

        self::assertSame('9.00', $this->lastVatRate);
        self::assertSame('9.00', $report['filters']['vatRate']);
        self::assertSame(['outgoing' => 0, 'incoming' => 0], $report['invoiceCount']);
    }

    public function testMonthlyShortcutIsUnchanged(): void
    {
        $report = $this->service([])->generateFor(new Company(), ['from' => new \DateTimeImmutable('2026-02-01'), 'to' => new \DateTimeImmutable('2026-02-28'), 'period' => '2026-02']);
        self::assertSame('2026-02', $report['period']);
        self::assertSame('0.00', $report['netVat']);
    }
}
