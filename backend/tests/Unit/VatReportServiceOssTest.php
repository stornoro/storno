<?php

namespace App\Tests\Unit;

use App\Entity\Client;
use App\Entity\Company;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Enum\InvoiceDirection;
use App\Repository\InvoiceRepository;
use App\Service\ExchangeRateService;
use App\Service\Report\VatReportService;
use PHPUnit\Framework\TestCase;

/**
 * The quarterly OSS view of the VAT report: VAT charged to EU consumers grouped by
 * state of consumption and converted to euro at the quarter-end ECB rate.
 */
class VatReportServiceOssTest extends TestCase
{
    private function invoice(string $country, bool $vatPayer, string $currency, array $lines, InvoiceDirection $direction = InvoiceDirection::OUTGOING): Invoice
    {
        $client = (new Client())->setCountry($country)->setIsVatPayer($vatPayer);
        $invoice = (new Invoice())->setDirection($direction)->setCurrency($currency)->setClient($client);
        $invoice->snapshotBuyer($client);
        foreach ($lines as [$rate, $base, $vat]) {
            $invoice->addLine((new InvoiceLine())->setDescription('x')->setQuantity('1')->setUnitPrice($base)->setVatRate($rate)->setLineTotal($base)->setVatAmount($vat));
        }

        return $invoice;
    }

    private function service(array $invoices, ?float $ronPerEur, ?float $ronPerUsd): VatReportService
    {
        $repo = $this->createMock(InvoiceRepository::class);
        $repo->method('findForVatReturn')->willReturn($invoices);
        $rates = $this->createMock(ExchangeRateService::class);
        $rates->method('getRateForDate')->willReturnCallback(fn(string $cur) => match ($cur) {
            'EUR' => $ronPerEur === null ? null : ['rate' => $ronPerEur, 'date' => '2026-09-30'],
            'USD' => $ronPerUsd === null ? null : ['rate' => $ronPerUsd, 'date' => '2026-09-30'],
            default => null,
        });

        return new VatReportService($repo, $rates);
    }

    private function company(): Company
    {
        return (new Company())->setName('X')->setCif(47087233)->setVatCode('RO47087233')->setOss(true);
    }

    public function testGroupsEuConsumerVatByCountryAndConvertsToEuro(): void
    {
        $invoices = [
            $this->invoice('DE', false, 'USD', [['19.00', '37.82', '7.18']]),
            $this->invoice('DE', false, 'USD', [['19.00', '37.82', '7.18']]),
            $this->invoice('SE', false, 'USD', [['25.00', '159.20', '39.80']]),
            $this->invoice('GR', false, 'EUR', [['24.00', '100.00', '24.00']]),
            $this->invoice('DE', true, 'USD', [['0.00', '45.00', '0.00']]),   // B2B, reverse charge
            $this->invoice('US', false, 'USD', [['0.00', '45.00', '0.00']]),  // outside the EU
            $this->invoice('RO', false, 'RON', [['21.00', '100.00', '21.00']]), // domestic
            $this->invoice('DE', false, 'EUR', [['19.00', '10.00', '1.90']], InvoiceDirection::INCOMING),
        ];
        // 1 EUR = 5.0 RON, 1 USD = 4.0 RON  → 1 USD = 0.80 EUR
        $report = $this->service($invoices, 5.0, 4.0)->generateOss($this->company(), 2026, 3);

        self::assertSame('2026-Q3', $report['period']);
        self::assertSame('2026-07-01', $report['from']);
        self::assertSame('2026-09-30', $report['to']);
        self::assertSame('2026-10-31', $report['payment']['dueDate']);
        self::assertSame('RO/RO47087233/Q3.2026', $report['payment']['reference']);
        self::assertTrue($report['ossEnabled']);
        self::assertSame('2026-09-30', $report['exchange']['date']);
        self::assertSame('0.800000', $report['exchange']['rates']['USD']['eurPerUnit']);
        self::assertSame('1.2500', $report['exchange']['rates']['USD']['unitsPerEur']);
        self::assertSame([], $report['exchange']['missing']);

        self::assertSame(['DE', 'EL', 'SE'], array_column($report['countries'], 'country'));
        $de = $report['countries'][0];
        self::assertSame(2, $de['invoiceCount']);
        self::assertSame('75.64', $de['rates'][0]['taxableBase']);
        self::assertSame('14.36', $de['rates'][0]['vatAmount']);
        self::assertSame('USD', $de['rates'][0]['currency']);
        self::assertSame('11.48', $de['rates'][0]['vatAmountEur']);
        self::assertSame('11.48', $de['vatAmountEur']);
        $el = $report['countries'][1];
        self::assertSame('24.00', $el['vatAmountEur']); // EUR invoices are taken as they are
        $se = $report['countries'][2];
        self::assertSame('31.84', $se['vatAmountEur']);

        self::assertSame('67.32', $report['totals']['vatAmountEur']);
        self::assertSame(4, $report['totals']['invoiceCount']);
        self::assertTrue($report['totals']['complete']);
    }

    public function testEuroAmountsAreNullWhileTheRateIsUnknown(): void
    {
        $invoices = [$this->invoice('DE', false, 'USD', [['19.00', '37.82', '7.18']])];
        $report = $this->service($invoices, null, null)->generateOss($this->company(), 2026, 4);

        self::assertSame(['USD'], $report['exchange']['missing']);
        self::assertNull($report['countries'][0]['vatAmountEur']);
        self::assertSame('7.18', $report['countries'][0]['rates'][0]['vatAmount']);
        self::assertNull($report['totals']['vatAmountEur']);
        self::assertFalse($report['totals']['complete']);
        self::assertSame('2027-01-31', $report['payment']['dueDate']);
    }

    public function testEmptyQuarter(): void
    {
        $report = $this->service([], 5.0, 4.0)->generateOss($this->company(), 2026, 1);
        self::assertSame([], $report['countries']);
        self::assertSame('0.00', $report['totals']['vatAmountEur']);
        self::assertNull($report['exchange']['date']);
    }
}
