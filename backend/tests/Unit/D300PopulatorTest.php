<?php

namespace App\Tests\Unit;

use App\Entity\Client;
use App\Entity\Company;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\Product;
use App\Entity\Supplier;
use App\Enum\InvoiceDirection;
use App\Enum\InvoiceTypeCode;
use App\Repository\InvoiceRepository;
use App\Repository\TaxDeclarationRepository;
use App\Service\Declaration\D300\D300Layout;
use App\Service\Declaration\Populator\D300Populator;
use PHPUnit\Framework\TestCase;

class D300PopulatorTest extends TestCase
{
    private Company $company;

    protected function setUp(): void
    {
        $this->company = new Company();
        $this->company->setName('Firma Test SRL');
        $this->company->setCif(12345678);
    }

    private function invoice(string $direction, string $issueDate, array $lines, array $opts = []): Invoice
    {
        $inv = new Invoice();
        $inv->setCompany($this->company);
        $inv->setDirection($direction === 'out' ? InvoiceDirection::OUTGOING : InvoiceDirection::INCOMING);
        $inv->setIssueDate(new \DateTime($issueDate));
        $inv->setCurrency($opts['currency'] ?? 'RON');
        if (isset($opts['exchangeRate'])) {
            $inv->setExchangeRate($opts['exchangeRate']);
        }
        if (isset($opts['type'])) {
            $inv->setInvoiceTypeCode($opts['type']);
        }
        if ($direction === 'out') {
            $client = new Client();
            $client->setCompany($this->company);
            $client->setName('Client');
            $client->setCountry($opts['country'] ?? 'RO');
            $client->setCui($opts['cui'] ?? 'RO99999999');
            $inv->setClient($client);
            $inv->setReceiverCif($opts['cui'] ?? null);
        } else {
            $supplier = new Supplier();
            $supplier->setCompany($this->company);
            $supplier->setName('Furnizor');
            $supplier->setCountry($opts['country'] ?? 'RO');
            $inv->setSupplier($supplier);
        }
        foreach ($lines as $l) {
            $line = new InvoiceLine();
            $line->setDescription($l['d'] ?? 'x');
            $line->setQuantity('1');
            $line->setUnitPrice($l['base']);
            $line->setLineTotal($l['base']);
            $line->setVatRate($l['rate']);
            $line->setVatCategoryCode($l['cat'] ?? 'S');
            $line->setVatAmount($l['vat'] ?? bcdiv(bcmul($l['base'], $l['rate'], 4), '100', 2));
            if (!empty($l['service'])) {
                $p = new Product();
                $p->setCompany($this->company);
                $p->setName('serviciu');
                $p->setIsService(true);
                $line->setProduct($p);
            }
            $inv->addLine($line);
        }
        return $inv;
    }

    /** @param Invoice[] $invoices */
    private function populate(array $invoices, int $year = 2026, int $month = 2, string $period = 'monthly', array $previousRows = []): array
    {
        $repo = $this->createMock(InvoiceRepository::class);
        $repo->method('findForVatReturn')->willReturn($invoices);
        $decls = $this->createMock(TaxDeclarationRepository::class);
        $prev = [];
        if ($previousRows) {
            $d = new \App\Entity\TaxDeclaration();
            $d->setData(['rows' => $previousRows]);
            $prev = [$d];
        }
        $decls->method('findByPeriod')->willReturn($prev);
        return (new D300Populator($repo, $decls))->populate($this->company, $year, $month, $period);
    }

    public function testLayoutByPeriod(): void
    {
        self::assertSame(D300Layout::LEGACY, D300Layout::forPeriodStart(new \DateTimeImmutable('2025-07-01')));
        self::assertSame(D300Layout::V2025H2, D300Layout::forPeriodStart(new \DateTimeImmutable('2025-08-01')));
        self::assertSame(D300Layout::V2026, D300Layout::forPeriodStart(new \DateTimeImmutable('2026-01-01')));
    }

    public function testDomesticSalesAndPurchasesByRateIn2026(): void
    {
        $data = $this->populate([
            $this->invoice('out', '2026-02-10', [['base' => '1000.00', 'rate' => '21'], ['base' => '200.00', 'rate' => '11']]),
            $this->invoice('in', '2026-02-12', [['base' => '500.00', 'rate' => '21'], ['base' => '100.00', 'rate' => '11']]),
        ]);
        $r = $data['rows'];
        self::assertSame(D300Layout::V2026, $data['layout']);
        self::assertSame('1000', $r['R9_1']);
        self::assertSame('210', $r['R9_2']);
        self::assertSame('200', $r['R10_1']);
        self::assertSame('22', $r['R10_2']);
        self::assertSame('500', $r['R22_1']);
        self::assertSame('105', $r['R22_2']);
        self::assertSame('100', $r['R23_1']);
        self::assertSame('11', $r['R23_2']);
        // totals: rd.19 collected, rd.30 deductible, rd.35 deducted, rd.37 to pay, rd.44 balance to pay
        self::assertSame('1200', $r['R17_1']);
        self::assertSame('232', $r['R17_2']);
        self::assertSame('600', $r['R27_1']);
        self::assertSame('116', $r['R27_2']);
        self::assertSame('116', $r['R32_2']);
        self::assertSame('116', $r['R34_2']);
        self::assertSame('0', $r['R33_2']);
        self::assertSame('116', $r['R41_2']);
        self::assertSame('0', $r['R42_2']);
        self::assertSame('232', $data['totals']['collected']);
        self::assertSame('116', $data['totals']['toPay']);
        self::assertArrayNotHasKey('R1_1', $r);
    }

    public function testAbrogatedRatesAreRegularisationsIn2026ButOwnRowsIn2025H2(): void
    {
        $inv = [
            $this->invoice('out', '2026-02-10', [['base' => '100.00', 'rate' => '19']]),
            $this->invoice('in', '2026-02-10', [['base' => '50.00', 'rate' => '9']]),
        ];
        $r = $this->populate($inv)['rows'];
        self::assertSame('100', $r['R16_1']);
        self::assertSame('19', $r['R16_2']);
        self::assertSame('50', $r['R30_1']);
        self::assertSame('5', $r['R30_2']);  // 4.50 → 5, half up
        self::assertArrayNotHasKey('R9_1', $r);

        $inv = [
            $this->invoice('out', '2025-09-10', [['base' => '100.00', 'rate' => '19'], ['base' => '100.00', 'rate' => '21']]),
            $this->invoice('in', '2025-09-10', [['base' => '50.00', 'rate' => '9'], ['base' => '50.00', 'rate' => '5']]),
        ];
        $data = $this->populate($inv, 2025, 9);
        $r = $data['rows'];
        self::assertSame(D300Layout::V2025H2, $data['layout']);
        self::assertSame('100', $r['R69_1']); // rd.9.1 19%
        self::assertSame('100', $r['R9_1']);  // rd.9 21%
        self::assertSame('50', $r['R75_1']);  // rd.25.1 9%
        self::assertSame('50', $r['R24_1']);  // rd.26 5%
        self::assertSame('200', $r['R17_1']);
        self::assertSame('40', $r['R17_2']); // 19 + 21
    }

    public function testLegacyLayoutMapsOldRatesToTheOldRows(): void
    {
        $r = $this->populate([
            $this->invoice('out', '2025-03-10', [['base' => '100.00', 'rate' => '19'], ['base' => '100.00', 'rate' => '9'], ['base' => '100.00', 'rate' => '5']]),
        ], 2025, 3)['rows'];
        self::assertSame('100', $r['R9_1']);
        self::assertSame('19', $r['R9_2']);
        self::assertSame('100', $r['R10_1']);
        self::assertSame('9', $r['R10_2']);
        self::assertSame('100', $r['R11_1']);
        self::assertSame('5', $r['R11_2']);
    }

    public function testReverseChargeIsSelfAssessedOnBothSides(): void
    {
        $r = $this->populate([
            // domestic reverse charge purchase (AE, no VAT on the invoice) at 21%
            $this->invoice('in', '2026-02-05', [['base' => '1000.00', 'rate' => '0', 'cat' => 'AE', 'vat' => '0.00']]),
            // intra-community goods purchase
            $this->invoice('in', '2026-02-06', [['base' => '2000.00', 'rate' => '0', 'cat' => 'K', 'vat' => '0.00']], ['country' => 'DE']),
            // intra-community service purchase
            $this->invoice('in', '2026-02-07', [['base' => '300.00', 'rate' => '0', 'cat' => 'AE', 'vat' => '0.00', 'service' => true]], ['country' => 'FR']),
            // service from outside the EU
            $this->invoice('in', '2026-02-08', [['base' => '400.00', 'rate' => '0', 'cat' => 'O', 'vat' => '0.00', 'service' => true]], ['country' => 'US']),
            // domestic reverse charge sale
            $this->invoice('out', '2026-02-09', [['base' => '5000.00', 'rate' => '0', 'cat' => 'AE', 'vat' => '0.00']], ['type' => InvoiceTypeCode::REVERSE_CHARGE->value]),
        ])['rows'];
        // rd.12 / 12.1 and rd.26 / 26.1 with VAT at 21%
        self::assertSame('1000', $r['R12_1']);
        self::assertSame('210', $r['R12_2']);
        self::assertSame('1000', $r['R12_1_1']);
        self::assertSame('210', $r['R12_1_2']);
        self::assertSame('1000', $r['R25_1']);
        self::assertSame('210', $r['R25_1_2']);
        // rd.5 / 5.1 and rd.20 / 20.1
        self::assertSame('2000', $r['R5_1']);
        self::assertSame('420', $r['R5_2']);
        self::assertSame('2000', $r['R5_1_1']);
        self::assertSame('2000', $r['R18_1']);
        self::assertSame('420', $r['R18_1_2']);
        // rd.7 / 7.1 (EU service) + rd.7 only (US service), and rd.22 / 22.1
        self::assertSame('700', $r['R7_1']);
        self::assertSame('147', $r['R7_2']);
        self::assertSame('300', $r['R7_1_1']);
        self::assertSame('700', $r['R20_1']);
        self::assertSame('300', $r['R20_1_1']);
        // rd.13 base only
        self::assertSame('5000', $r['R13_1']);
        self::assertArrayNotHasKey('R13_2', $r);
        // collected = deductible for self-assessed VAT → nothing to pay
        self::assertSame('777', $r['R17_2']);
        self::assertSame('777', $r['R27_2']);
        self::assertSame('0', $r['R41_2']);
        self::assertSame('0', $r['R42_2']);
    }

    public function testExemptAndForeignSales(): void
    {
        $r = $this->populate([
            $this->invoice('out', '2026-02-01', [['base' => '100.00', 'rate' => '0', 'cat' => 'K', 'vat' => '0.00']], ['country' => 'IT']),               // rd.1
            $this->invoice('out', '2026-02-01', [['base' => '200.00', 'rate' => '0', 'cat' => 'K', 'vat' => '0.00', 'service' => true]], ['country' => 'IT']), // rd.3 + 3.1
            $this->invoice('out', '2026-02-01', [['base' => '300.00', 'rate' => '0', 'cat' => 'G', 'vat' => '0.00']], ['country' => 'US']),               // rd.3
            $this->invoice('out', '2026-02-01', [['base' => '400.00', 'rate' => '0', 'cat' => 'E', 'vat' => '0.00']]),                                    // rd.15
            $this->invoice('out', '2026-02-01', [['base' => '500.00', 'rate' => '0', 'cat' => 'E', 'vat' => '0.00']], ['type' => InvoiceTypeCode::EXEMPT_WITH_DEDUCTION->value]), // rd.14
            $this->invoice('out', '2026-02-01', [['base' => '600.00', 'rate' => '0', 'cat' => 'O', 'vat' => '0.00']]),                                    // outside the decont
        ])['rows'];
        self::assertSame('100', $r['R1_1']);
        self::assertSame('500', $r['R3_1']);
        self::assertSame('200', $r['R3_1_1']);
        self::assertSame('400', $r['R15_1']);
        self::assertSame('500', $r['R14_1']);
        self::assertSame('1500', $r['R17_1']); // 100 + 500 + 500 + 400 (3.1 excluded, O excluded)
        self::assertSame('0', $r['R17_2']);
    }

    public function testSupplierInvoiceDatedBeforeThePeriodIsARegularisation(): void
    {
        $r = $this->populate([
            $this->invoice('in', '2025-12-20', [['base' => '100.00', 'rate' => '21']]),
        ])['rows'];
        self::assertSame('100', $r['R30_1']);
        self::assertSame('21', $r['R30_2']);
        self::assertArrayNotHasKey('R22_1', $r);
        self::assertSame('21', $r['R32_2']);
    }

    public function testSelfInvoiceIsACollectedRegularisationAndExemptPurchasesGoToRd29(): void
    {
        $r = $this->populate([
            $this->invoice('out', '2026-02-01', [['base' => '100.00', 'rate' => '21']], ['cui' => 'RO12345678']),
            $this->invoice('in', '2026-02-01', [['base' => '80.00', 'rate' => '0', 'cat' => 'E', 'vat' => '0.00']]),
            $this->invoice('in', '2026-02-01', [['base' => '70.00', 'rate' => '0', 'cat' => 'Z', 'vat' => '0.00']], ['country' => 'CN']), // imported goods
        ])['rows'];
        self::assertSame('100', $r['R16_1']);
        self::assertSame('21', $r['R16_2']);
        self::assertSame('150', $r['R26_1']);
        self::assertArrayNotHasKey('R9_1', $r);
    }

    public function testForeignCurrencyUsesTheInvoiceRateAndNegativeBalanceIsCarried(): void
    {
        $data = $this->populate([
            $this->invoice('in', '2026-02-01', [['base' => '100.00', 'rate' => '21']], ['currency' => 'EUR', 'exchangeRate' => '5.0000']),
        ], previousRows: ['R42_2' => '300', 'solicit_ramb' => 'N']);
        $r = $data['rows'];
        self::assertSame('500', $r['R22_1']);
        self::assertSame('105', $r['R22_2']);
        self::assertSame('300', $r['R38_2']);
        self::assertSame('405', $r['R40_2']);
        self::assertSame('405', $r['R42_2']);
        self::assertSame('405', $data['totals']['toRecover']);
    }

    public function testQuarterlyPeriodBounds(): void
    {
        [$from, $to] = D300Populator::periodBounds(2026, 5, 'quarterly');
        self::assertSame('2026-04-01', $from->format('Y-m-d'));
        self::assertSame('2026-06-30', $to->format('Y-m-d'));
    }

    public function testRoundingIsHalfUpPerRow(): void
    {
        self::assertSame(3, D300Populator::roundLei('2.50'));
        self::assertSame(2, D300Populator::roundLei('2.49'));
        self::assertSame(-2, D300Populator::roundLei('-2.50'));
        self::assertSame(-3, D300Populator::roundLei('-2.51'));
    }
}
