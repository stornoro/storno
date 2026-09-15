<?php

namespace App\Tests\Unit;

use App\Service\ExchangeRateService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class ExchangeRateServiceTest extends TestCase
{
    private function bnrXml(string $date = '2026-04-19'): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<DataSet>
  <Body>
    <Cube date="$date">
      <Rate currency="EUR">4.9750</Rate>
      <Rate currency="USD">4.6500</Rate>
      <Rate currency="HUF" multiplier="100">1.2300</Rate>
    </Cube>
  </Body>
</DataSet>
XML;
    }

    private function makeService(MockHttpClient $client, ArrayAdapter $cache = null): ExchangeRateService
    {
        $cache ??= new ArrayAdapter();
        return new ExchangeRateService($client, $cache, $cache, new NullLogger());
    }

    public function testHappyPathPopulatesBothCaches(): void
    {
        $cache = new ArrayAdapter();
        $svc = $this->makeService(new MockHttpClient(new MockResponse($this->bnrXml())), $cache);

        $rates = $svc->getRates();

        $this->assertSame('2026-04-19', $rates['date']);
        $this->assertCount(3, $rates['rates']);
        $this->assertArrayNotHasKey('stale', $rates);

        // last-good slot must be populated for future failure recovery
        $this->assertTrue($cache->getItem('bnr_exchange_rates_last_good')->isHit());
    }

    public function testFetchFailureWithStaleCacheReturnsStaleData(): void
    {
        $cache = new ArrayAdapter();

        // First request succeeds → seeds the last-good cache
        $okClient = new MockHttpClient(new MockResponse($this->bnrXml('2026-04-18')));
        $svc1 = $this->makeService($okClient, $cache);
        $svc1->getRates();

        // Wipe today's cache so the next call re-fetches
        $cache->deleteItem('bnr_exchange_rates_' . date('Y-m-d'));

        // Second request — upstream is broken (SSL expired etc.)
        $brokenClient = new MockHttpClient(function () {
            throw new TransportException('OpenSSL verify result: certificate has expired (10)');
        });
        $svc2 = $this->makeService($brokenClient, $cache);

        $rates = $svc2->getRates();

        // Falls back to last-good — does NOT throw
        $this->assertSame('2026-04-18', $rates['date']);
        $this->assertCount(3, $rates['rates']);
        $this->assertTrue($rates['stale']);
        $this->assertSame(4.975, $svc2->getRate('EUR'));
    }

    public function testFetchFailureWithNoCacheReturnsEmptyRatesGracefully(): void
    {
        // Brand-new install + BNR is broken → no cached anything
        $cache = new ArrayAdapter();
        $brokenClient = new MockHttpClient(function () {
            throw new TransportException('OpenSSL verify result: certificate has expired (10)');
        });
        $svc = $this->makeService($brokenClient, $cache);

        $rates = $svc->getRates();

        // The crucial assertion — does NOT throw, returns an empty-but-shaped array
        $this->assertSame([], $rates['rates']);
        $this->assertTrue($rates['stale']);
        // getRate gracefully returns null instead of crashing
        $this->assertNull($svc->getRate('EUR'));
    }

    private function ecbCsv(array $rows): string
    {
        $csv = "KEY,FREQ,CURRENCY,CURRENCY_DENOM,EXR_TYPE,EXR_SUFFIX,TIME_PERIOD,OBS_VALUE,OBS_STATUS\n";
        foreach ($rows as [$currency, $date, $value]) {
            $csv .= sprintf("EXR.D.%s.EUR.SP00.A,D,%s,EUR,SP00,A,%s,%s,A\n", $currency, $currency, $date, $value);
        }
        return $csv;
    }

    public function testDatedRateIsTheEcbPublicationOfTheDayOrTheNeighbouringOne(): void
    {
        $requests = [];
        $client = new MockHttpClient(function (string $method, string $url) use (&$requests) {
            $requests[] = $url;
            return new MockResponse($this->ecbCsv([['RON', '2026-06-30', '5.2439'], ['RON', '2026-07-01', '5.2367'], ['RON', '2026-07-06', '5.2300']]));
        });
        $svc = $this->makeService($client);

        // Published that day
        $this->assertSame(['rate' => 5.2439, 'date' => '2026-06-30'], $svc->getRateForDate('EUR', new \DateTimeImmutable('2026-06-30')));
        $this->assertStringContainsString('D.RON.EUR.SP00.A', $requests[0]);
        $this->assertStringContainsString('startPeriod=2026-06-23&endPeriod=2026-06-30', $requests[0]);

        // Saturday 2026-07-04: the most recent earlier publication, or the next one for the OSS rule
        $this->assertSame(['rate' => 5.2367, 'date' => '2026-07-01'], $svc->getRateForDate('EUR', new \DateTimeImmutable('2026-07-04')));
        $this->assertSame(['rate' => 5.23, 'date' => '2026-07-06'], $svc->getRateForDate('EUR', new \DateTimeImmutable('2026-07-04'), true));
        $this->assertStringContainsString('startPeriod=2026-07-04&endPeriod=2026-07-11', $requests[2]);

        // The same window is served from cache
        $svc->getRateForDate('EUR', new \DateTimeImmutable('2026-07-04'), true);
        $this->assertCount(3, $requests);
    }

    public function testDatedRateOfAnotherCurrencyIsTheCrossRateThroughTheEuro(): void
    {
        $svc = $this->makeService(new MockHttpClient(new MockResponse($this->ecbCsv([['RON', '2026-06-30', '5.0000'], ['USD', '2026-06-30', '1.2500']]))));
        $this->assertSame(['rate' => 4.0, 'date' => '2026-06-30'], $svc->getRateForDate('USD', new \DateTimeImmutable('2026-06-30')));
        $this->assertSame(['rate' => 1.0, 'date' => '2026-06-30'], $svc->getRateForDate('RON', new \DateTimeImmutable('2026-06-30')));
    }

    public function testDatedRateIsNullForTheFutureAndWhenTheEcbIsDown(): void
    {
        $calls = 0;
        $client = new MockHttpClient(function () use (&$calls) {
            $calls++;
            throw new TransportException('ECB unreachable');
        });
        $svc = $this->makeService($client);
        $this->assertNull($svc->getRateForDate('EUR', new \DateTimeImmutable('+30 days'), true));
        $this->assertSame(0, $calls, 'a date in the future is not looked up');
        $this->assertNull($svc->getRateForDate('EUR', new \DateTimeImmutable('2026-06-30')));
        $this->assertSame(1, $calls);
    }
}
