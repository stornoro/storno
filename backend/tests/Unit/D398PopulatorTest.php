<?php

namespace App\Tests\Unit;

use App\Entity\Client;
use App\Entity\Company;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\Product;
use App\Entity\TaxDeclaration;
use App\Enum\DeclarationType;
use App\Enum\InvoiceDirection;
use App\Enum\InvoiceTypeCode;
use App\Repository\InvoiceRepository;
use App\Service\Declaration\Populator\D398Populator;
use App\Service\Declaration\XmlGenerator\D398XmlGenerator;
use App\Service\EuVatRateService;
use App\Service\ExchangeRateService;
use PHPUnit\Framework\TestCase;

class D398PopulatorTest extends TestCase
{
    private Company $company;

    protected function setUp(): void
    {
        $this->company = new Company();
        $this->company->setName('Firma Test SRL');
        $this->company->setCif(12345678);
        $this->company->setVatPayer(true);
        $this->company->setVatCode('RO12345678');
    }

    /** @param array<int, array{base: string, rate?: string, service?: bool}> $lines */
    private function invoice(string $country, string $issueDate, array $lines, array $opts = []): Invoice
    {
        $inv = new Invoice();
        $inv->setCompany($this->company);
        $inv->setDirection(InvoiceDirection::OUTGOING);
        $inv->setIssueDate(new \DateTime($issueDate));
        $inv->setNumber($opts['number'] ?? 'F-1');
        $inv->setCurrency($opts['currency'] ?? 'EUR');
        $inv->setInvoiceTypeCode($opts['type'] ?? InvoiceTypeCode::SPECIAL_REGIME_ART_314_315->value);
        if (isset($opts['exchangeRate'])) {
            $inv->setExchangeRate($opts['exchangeRate']);
        }
        $client = new Client();
        $client->setCompany($this->company);
        $client->setName('Consumer ' . $country);
        $client->setCountry($country);
        $client->setType('individual');
        $inv->setClient($client);
        foreach ($lines as $l) {
            $line = new InvoiceLine();
            $line->setDescription('x');
            $line->setQuantity('1');
            $line->setUnitPrice($l['base']);
            $line->setLineTotal($l['base']);
            $line->setVatRate($l['rate'] ?? '0');
            $line->setVatAmount('0.00');
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
    /** @param array{rate: float, date: string}|null $datedEurRate what ExchangeRateService::getRateForDate answers for the quarter end */
    private function populate(array $invoices, ?array $liveRates = null, ?float $eurRate = 5.0, int $year = 2026, int $month = 4, ?array $datedEurRate = null): array
    {
        $repo = $this->createMock(InvoiceRepository::class);
        $repo->method('findForVatReturn')->willReturn($invoices);
        $eu = $this->createMock(EuVatRateService::class);
        $eu->method('getAllRates')->willReturnCallback(static fn (string $c) => $liveRates[$c] ?? null);
        $fx = $this->createMock(ExchangeRateService::class);
        $fx->method('getRate')->willReturn($eurRate);
        $fx->method('getRateForDate')->willReturnCallback(static function (string $currency, \DateTimeInterface $date, bool $nextPublished = false) use ($datedEurRate): ?array {
            self::assertSame('EUR', $currency);
            self::assertSame('2026-06-30', $date->format('Y-m-d'), 'the rate of the last day of the quarter is asked for');
            self::assertTrue($nextPublished, 'when nothing was published on the last day, the next publication counts');
            return $datedEurRate;
        });
        return (new D398Populator($repo, $eu, $fx))->populate($this->company, $year, $month, 'quarterly');
    }

    public function testOneSupplyPerStateTypeAndRateWithTheDestinationRate(): void
    {
        $data = $this->populate([
            $this->invoice('DE', '2026-05-10', [['base' => '1000.00'], ['base' => '100.00', 'service' => true]]),
            $this->invoice('DE', '2026-06-10', [['base' => '500.00']]),
            $this->invoice('FR', '2026-04-10', [['base' => '200.00', 'rate' => '5.5']]),
        ], ['DE' => ['standard' => 19.0, 'reduced' => 7.0], 'FR' => ['standard' => 20.0, 'reduced1' => 10.0, 'reduced2' => 5.5]]);

        self::assertSame('2026', $data['rows']['an_r']);
        self::assertSame('6', $data['rows']['luna_r'], 'quarter Q2 is reported under its last month');
        self::assertSame('01.04.2026', $data['rows']['period_start_date']);
        self::assertSame('30.06.2026', $data['rows']['period_end_date']);
        self::assertSame('1', $data['rows']['moes_voes_imp']);
        self::assertSame('0', $data['rows']['nil_vat_return']);
        self::assertSame('1', $data['rows']['totalPlata_A']);
        self::assertSame('RO12345678', $data['rows']['vat_id_no']);
        self::assertSame('EUR', $data['rows']['currency']);

        self::assertCount(2, $data['states']);
        [$de, $fr] = $data['states'];
        self::assertSame('DE', $de['mscon_state']);
        self::assertCount(2, $de['supplies']);
        $goods = $de['supplies'][0];
        self::assertSame(['1', '1', '1', '19', '1500.00', '285.00'], [$goods['trade_type'], $goods['supply_type'], $goods['vat_rate_type'], $goods['vat_rate'], $goods['taxable_amount'], $goods['vat_amount']]);
        $services = $de['supplies'][1];
        self::assertSame(['2', '19', '100.00', '19.00'], [$services['supply_type'], $services['vat_rate'], $services['taxable_amount'], $services['vat_amount']]);
        self::assertSame('285.00', $de['vat_total_goods_msid']);
        self::assertSame('19.00', $de['vat_total_services_msid']);
        self::assertSame('304.00', $de['grand_total']);
        self::assertSame('304.00', $de['due_balance']);

        self::assertSame('FR', $fr['mscon_state']);
        self::assertSame('2', $fr['supplies'][0]['vat_rate_type'], 'a line at one of the state\'s reduced rates keeps it');
        self::assertSame('5.5', $fr['supplies'][0]['vat_rate']);
        self::assertSame('11.00', $fr['supplies'][0]['vat_amount']);
        self::assertSame('315.00', $data['rows']['grand_total_vat_due']);
        self::assertSame('315.00', $data['totals']['vatDue']);
        self::assertNotContains('EUR_RATE_APPROXIMATE', array_column($data['warnings'], 'code'));
    }

    public function testRonInvoicesAreConvertedWithTheEcbRateOfTheLastDayOfTheQuarter(): void
    {
        $data = $this->populate([
            $this->invoice('IT', '2026-04-10', [['base' => '1000.00']], ['currency' => 'RON']),
            $this->invoice('IT', '2026-04-11', [['base' => '100.00']], ['currency' => 'USD', 'exchangeRate' => '4.5000']),
        ], ['IT' => ['standard' => 22.0]], 4.0, 2026, 4, ['rate' => 5.0, 'date' => '2026-06-30']);
        $s = $data['states'][0]['supplies'][0];
        self::assertSame('290.00', $s['taxable_amount']); // 1000 / 5 + 100 × 4.5 / 5, at the dated rate, not the live 4.0
        self::assertSame('63.80', $s['vat_amount']);
        self::assertSame(['rate' => '5.0000', 'date' => '2026-06-30', 'source' => 'ecb'], $data['eurRate']);
        $codes = array_column($data['warnings'], 'code');
        self::assertNotContains('EUR_RATE_FALLBACK', $codes);
        self::assertNotContains('EUR_RATE_APPROXIMATE', $codes);
    }

    public function testWithoutTheDatedRateTheLiveRateIsUsedAndFlagged(): void
    {
        $data = $this->populate([
            $this->invoice('IT', '2026-04-10', [['base' => '1000.00']], ['currency' => 'RON']),
        ], ['IT' => ['standard' => 22.0]], 5.0);
        self::assertSame('200.00', $data['states'][0]['supplies'][0]['taxable_amount']);
        self::assertSame('bnr', $data['eurRate']['source']);
        self::assertSame('5.0000', $data['eurRate']['rate']);
        self::assertContains('EUR_RATE_FALLBACK', array_column($data['warnings'], 'code'));

        // EUR-only returns need no conversion and carry no rate
        $eurOnly = $this->populate([$this->invoice('IT', '2026-04-10', [['base' => '100.00']])], ['IT' => ['standard' => 22.0]], 5.0);
        self::assertNull($eurOnly['eurRate']);
        self::assertNotContains('EUR_RATE_FALLBACK', array_column($eurOnly['warnings'], 'code'));
    }

    public function testWithoutAnEurRateRonLinesAreLeftOut(): void
    {
        $data = $this->populate([$this->invoice('IT', '2026-04-10', [['base' => '1000.00']], ['currency' => 'RON'])], ['IT' => ['standard' => 22.0]], null);
        self::assertSame([], $data['states']);
        self::assertSame('1', $data['rows']['nil_vat_return']);
        self::assertSame('2', $data['rows']['totalPlata_A']);
        self::assertContains('MISSING_EUR_RATE', array_column($data['warnings'], 'code'));
    }

    public function testValidatorRateListIsTheFallbackWhenTheFeedIsDown(): void
    {
        $data = $this->populate([$this->invoice('HU', '2026-04-10', [['base' => '100.00']])], null);
        self::assertSame('27', $data['states'][0]['supplies'][0]['vat_rate']);
        self::assertSame('27.00', $data['states'][0]['supplies'][0]['vat_amount']);
        self::assertContains('EU_RATES_OFFLINE', array_column($data['warnings'], 'code'));
    }

    public function testOnlySpecialRegimeSalesToEuConsumersOutsideRomaniaCount(): void
    {
        $data = $this->populate([
            $this->invoice('DE', '2026-04-10', [['base' => '100.00']], ['type' => InvoiceTypeCode::STANDARD->value]),
            $this->invoice('RO', '2026-04-10', [['base' => '100.00']]),
            $this->invoice('US', '2026-04-10', [['base' => '100.00']]),
            $this->invoice('XI', '2026-04-10', [['base' => '100.00', 'service' => true]]),
        ], ['DE' => ['standard' => 19.0]]);
        self::assertSame([], $data['states']);
        $codes = array_column($data['warnings'], 'code');
        self::assertContains('DOMESTIC_EXCLUDED', $codes);
        self::assertContains('NON_EU_EXCLUDED', $codes);
        self::assertContains('XI_SERVICES_EXCLUDED', $codes);
        self::assertSame(['issued' => 4, 'oss' => 3, 'domestic' => 1, 'nonEu' => 1, 'skipped' => 0], $data['invoiceCounts']);

        $this->company->setVatPayer(false);
        self::assertContains('COMPANY_NOT_VAT_PAYER', array_column($this->populate([])['warnings'], 'code'));
    }

    public function testXmlStructureAndRecomputedTotals(): void
    {
        $data = $this->populate([
            $this->invoice('DE', '2026-05-10', [['base' => '1000.00'], ['base' => '100.00', 'service' => true]]),
        ], ['DE' => ['standard' => 19.0, 'reduced' => 7.0]]);
        $decl = new TaxDeclaration();
        $decl->setCompany($this->company);
        $decl->setType(DeclarationType::D398);
        $decl->setYear(2026);
        $decl->setMonth(6);
        $decl->setPeriodType('quarterly');
        $decl->setData($data);

        $xml = (new D398XmlGenerator())->generate($decl);
        $doc = new \DOMDocument();
        self::assertTrue($doc->loadXML($xml));
        $root = $doc->documentElement;
        self::assertSame('d398', $root->tagName);
        self::assertSame('6', $root->getAttribute('luna_r'));
        self::assertSame('1', $root->getAttribute('moes_voes_imp'));
        self::assertSame('0', $root->getAttribute('nil_vat_return'));
        self::assertSame('1', $root->getAttribute('totalPlata_A'));
        self::assertSame('209.00', $root->getAttribute('grand_total_vat_due'));
        self::assertSame('RO12345678', $root->getAttribute('vat_id_no'));
        self::assertFalse($root->hasAttribute('intermediary_id'));
        $ms = $root->getElementsByTagName('MS');
        self::assertSame(1, $ms->length);
        self::assertSame('DE', $ms->item(0)->getAttribute('mscon_state'));
        self::assertSame('209.00', $ms->item(0)->getAttribute('grand_total'));
        self::assertSame('190.00', $ms->item(0)->getAttribute('vat_total_goods_msid'));
        self::assertSame('0.00', $ms->item(0)->getAttribute('vat_total_goods_msest'));
        $supplies = $ms->item(0)->getElementsByTagName('SUPPLY');
        self::assertSame(2, $supplies->length);
        self::assertSame('19', $supplies->item(0)->getAttribute('vat_rate'));
        self::assertFalse($supplies->item(0)->hasAttribute('vat_id_no_msest'));

        // A nil return has no MS and totalPlata_A = 1 + 1
        $decl->setData($this->populate([]));
        $doc->loadXML((new D398XmlGenerator())->generate($decl));
        self::assertSame('1', $doc->documentElement->getAttribute('nil_vat_return'));
        self::assertSame('2', $doc->documentElement->getAttribute('totalPlata_A'));
        self::assertSame(0, $doc->getElementsByTagName('MS')->length);
    }
}
