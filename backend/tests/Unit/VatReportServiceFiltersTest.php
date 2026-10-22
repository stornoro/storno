<?php

namespace App\Tests\Unit;

use App\Entity\Company;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Enum\DocumentStatus;
use App\Enum\InvoiceDirection;
use App\Repository\InvoiceRepository;
use App\Service\ExchangeRateService;
use App\Service\Report\VatReportService;
use PHPUnit\Framework\TestCase;

/** The VAT summary over an arbitrary period with the direction / rate / status filters. */
class VatReportServiceFiltersTest extends TestCase
{
    private array $lastFilters = [];

    private function invoice(InvoiceDirection $dir, DocumentStatus $status, string $currency, array $lines): Invoice
    {
        $inv = (new Invoice())->setDirection($dir)->setStatus($status)->setCurrency($currency);
        $sub = '0.00'; $vat = '0.00';
        foreach ($lines as [$rate, $base, $vatAmount]) {
            $inv->addLine((new InvoiceLine())->setDescription('x')->setQuantity('1')->setUnitPrice($base)->setVatRate($rate)->setLineTotal($base)->setVatAmount($vatAmount));
            $sub = bcadd($sub, $base, 2); $vat = bcadd($vat, $vatAmount, 2);
        }
        $inv->setSubtotal($sub)->setVatTotal($vat)->setTotal(bcadd($sub, $vat, 2));

        return $inv;
    }

    private function service(array $invoices): VatReportService
    {
        $repo = $this->createMock(InvoiceRepository::class);
        $repo->method('findByCompanyFiltered')->willReturnCallback(function (Company $c, array $filters) use ($invoices) {
            $this->lastFilters = $filters;
            return $invoices;
        });

        return new VatReportService($repo, $this->createMock(ExchangeRateService::class));
    }

    private function invoices(): array
    {
        return [
            $this->invoice(InvoiceDirection::OUTGOING, DocumentStatus::ISSUED, 'RON', [['21', '100.00', '21.00'], ['9', '50.00', '4.50']]),
            $this->invoice(InvoiceDirection::OUTGOING, DocumentStatus::DRAFT, 'RON', [['21', '10.00', '2.10']]),
            $this->invoice(InvoiceDirection::INCOMING, DocumentStatus::VALIDATED, 'EUR', [['21', '200.00', '42.00']]),
        ];
    }

    public function testPeriodAndRepositoryFiltersArePassedThrough(): void
    {
        $report = $this->service($this->invoices())->generateFor(new Company(), [
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
        self::assertSame('outgoing', $report['filters']['direction']);
    }

    public function testDefaultsKeepDraftsAndSumInvoiceTotals(): void
    {
        $report = $this->service($this->invoices())->generateFor(new Company(), ['from' => new \DateTimeImmutable('2026-09-01'), 'to' => new \DateTimeImmutable('2026-09-30')]);

        self::assertSame('2026-09-01..2026-09-30', $report['period']);
        self::assertSame('160.00', $report['totals']['outgoing']['taxableBase']);
        self::assertSame('27.60', $report['totals']['outgoing']['vatAmount']);
        self::assertSame('110.00', $report['outgoing']['21.00']['taxableBase']);
        self::assertSame('23.10', $report['outgoing']['21.00']['vatAmount']);
        self::assertSame('4.50', $report['outgoing']['9.00']['vatAmount']);
        self::assertSame('42.00', $report['totals']['incoming']['vatAmount']);
        self::assertSame('-14.40', $report['netVat']);
        self::assertSame(['outgoing' => 2, 'incoming' => 1], $report['invoiceCount']);
        self::assertSame(['EUR', 'RON'], $report['currencies']);
    }

    public function testFinalOnlyLeavesDraftsOut(): void
    {
        $report = $this->service($this->invoices())->generateFor(new Company(), ['from' => new \DateTimeImmutable('2026-09-01'), 'to' => new \DateTimeImmutable('2026-09-30'), 'finalOnly' => true]);

        self::assertSame('150.00', $report['totals']['outgoing']['taxableBase']);
        self::assertSame(1, $report['invoiceCount']['outgoing']);
        self::assertTrue($report['filters']['finalOnly']);
    }

    public function testVatRateFilterCountsOnlyTheLinesAtThatRate(): void
    {
        $report = $this->service($this->invoices())->generateFor(new Company(), ['from' => new \DateTimeImmutable('2026-09-01'), 'to' => new \DateTimeImmutable('2026-09-30'), 'vatRate' => '9']);

        self::assertSame('9.00', $report['filters']['vatRate']);
        self::assertSame('50.00', $report['totals']['outgoing']['taxableBase']);
        self::assertSame('4.50', $report['totals']['outgoing']['vatAmount']);
        self::assertSame('54.50', $report['totals']['outgoing']['total']);
        self::assertSame('0.00', $report['outgoing']['21.00']['vatAmount']);
        self::assertSame('0.00', $report['totals']['incoming']['vatAmount']);
        self::assertSame(['outgoing' => 1, 'incoming' => 0], $report['invoiceCount']);
    }

    public function testMonthlyShortcutIsUnchanged(): void
    {
        $report = $this->service([])->generateFor(new Company(), ['from' => new \DateTimeImmutable('2026-02-01'), 'to' => new \DateTimeImmutable('2026-02-28'), 'period' => '2026-02']);
        self::assertSame('2026-02', $report['period']);
        self::assertSame('0.00', $report['netVat']);
    }
}
