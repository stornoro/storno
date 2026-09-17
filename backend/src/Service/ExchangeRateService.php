<?php

namespace App\Service;

use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ExchangeRateService
{
    /** BNR serves its rate files from curs.bnr.ro; www.bnr.ro answers those paths with its home page. */
    private const BNR_URL = 'https://curs.bnr.ro/nbrfxrates.xml';
    private const BNR_YEAR_URL = 'https://curs.bnr.ro/files/xml/years/nbrfxrates%d.xml';
    private const LAST_GOOD_KEY = 'bnr_exchange_rates_last_good';
    private const LAST_GOOD_TTL = 86400 * 365; // ~1 year — effectively persistent
    private const FRESH_TTL = 86400; // 24h cache for successful fetches
    private const STALE_RETRY_TTL = 600; // 10min retry window when serving fallback / empty
    private const FAILURE_NOTIFICATION_TTL = 86400; // dedupe critical warning to once per day
    private const ECB_URL = 'https://data-api.ecb.europa.eu/service/data/EXR/D.%s.EUR.SP00.A';
    private const ECB_WINDOW_DAYS = 7; // publication gap the dated lookup bridges (weekends, holidays)

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly CacheItemPoolInterface $cachePool,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Get all BNR exchange rates for today.
     *
     * Tries today's cache first (24h TTL). On a fresh fetch failure, falls back
     * to the last successful response (cached separately with a year-long TTL).
     * Only returns an empty result if BNR has been broken AND we have no prior
     * cached rates at all (e.g. brand-new install during an outage).
     *
     * @return array{date: string, rates: array<string, array{value: float, multiplier: int}>, stale?: bool}
     */
    public function getRates(): array
    {
        $cacheKey = 'bnr_exchange_rates_' . date('Y-m-d');

        return $this->cache->get($cacheKey, function (ItemInterface $item) {
            $fresh = $this->fetchFromBnr();
            if ($fresh !== null) {
                // Successful fetch — cache for 24h (BNR publishes daily).
                $item->expiresAfter(self::FRESH_TTL);
                $this->storeLastGood($fresh);
                return $fresh;
            }

            // Anything below is a degraded result. Use a short TTL so the
            // next request retries the upstream — otherwise an empty / stale
            // result locks in for 24h and persists past the underlying outage.
            $item->expiresAfter(self::STALE_RETRY_TTL);

            // Fresh fetch failed — try the last known good rates.
            $stale = $this->loadLastGood();
            if ($stale !== null) {
                $this->logger->warning('[BNR] Using stale rates from {date}', [
                    'date' => $stale['date'],
                ]);
                $this->logFailureOnce('BNR upstream unreachable; serving cached rates from ' . $stale['date']);
                return $stale + ['stale' => true];
            }

            // No fresh, no stale — degrade gracefully so callers don't 500.
            $this->logger->critical('[BNR] No exchange rates available (fresh + last-good both missing)');
            $this->logFailureOnce('BNR upstream unreachable AND no cached rates available — currency conversions disabled');
            return ['date' => date('Y-m-d'), 'rates' => [], 'stale' => true];
        });
    }

    /**
     * Get exchange rate for a specific currency (relative to RON).
     * Returns null if currency not found.
     */
    public function getRate(string $currency): ?float
    {
        $currency = strtoupper($currency);

        if ($currency === 'RON') {
            return 1.0;
        }

        $data = $this->getRates();
        $rate = $data['rates'][$currency] ?? null;

        if (!$rate) {
            return null;
        }

        return $rate['value'] / $rate['multiplier'];
    }

    /**
     * ECB reference rate of a currency against RON on a given day.
     *
     * The ECB publishes reference rates (1 EUR = x units of each currency) on every TARGET
     * business day; a currency's rate in RON is RON/EUR ÷ currency/EUR of the same day. Looks
     * for the rate published on $date and, when there is none (weekend, holiday), the most
     * recent earlier publication — or, with $nextPublished, the first later one within a week
     * (the rule of the OSS return: the last day of the quarter or the next publication day).
     * Windows entirely in the past are immutable and cached for a year; a window reaching
     * today is retried after an hour. Null for dates in the future and when the ECB is
     * unreachable, so callers fall back to the live BNR rate.
     *
     * @return array{rate: float, date: string}|null
     */
    public function getRateForDate(string $currency, \DateTimeInterface $date, bool $nextPublished = false): ?array
    {
        $currency = strtoupper($currency);
        $day = \DateTimeImmutable::createFromInterface($date)->setTime(0, 0);
        if ($currency === 'RON') {
            return ['rate' => 1.0, 'date' => $day->format('Y-m-d')];
        }
        $today = new \DateTimeImmutable('today');
        if ($day > $today) {
            return null;
        }
        $start = $nextPublished ? $day : $day->modify('-' . self::ECB_WINDOW_DAYS . ' days');
        $end = $nextPublished ? min($day->modify('+' . self::ECB_WINDOW_DAYS . ' days'), $today) : $day;
        $series = $currency === 'EUR' ? ['RON'] : ['RON', $currency];

        $table = $this->fetchEcbWindow($series, $start, $end);
        if ($table === null) {
            return null;
        }
        $dates = array_keys($table['RON'] ?? []);
        if ($currency !== 'EUR') {
            $dates = array_values(array_intersect($dates, array_keys($table[$currency] ?? [])));
        }
        sort($dates);
        $target = $day->format('Y-m-d');
        $picked = null;
        foreach ($dates as $d) {
            if ($nextPublished ? $d >= $target : $d <= $target) {
                $picked = $d;
                if ($nextPublished) {
                    break;
                }
            }
        }
        if ($picked === null) {
            return null;
        }
        $rate = $currency === 'EUR' ? $table['RON'][$picked] : $table['RON'][$picked] / $table[$currency][$picked];

        return ['rate' => round($rate, 6), 'date' => $picked];
    }

    /**
     * The average annual exchange rate the National Bank of Romania publishes for a year
     * (the simple mean of its twelve monthly averages, each the mean of that month's daily rates).
     *
     * Income from renting out property for a rent expressed in a foreign currency, paid by a
     * natural person, is converted to lei with this rate (Codul fiscal, venituri din cedarea
     * folosinței bunurilor): the gross annual income is the contractual rent evaluated at the
     * average annual rate of the year the income was earned. A rent paid by a company is taxed
     * at source and uses the rate of the day before the payment instead.
     *
     * The rates BNR published are listed here; for a year that is not listed the mean of the
     * monthly means is computed from the daily series and reported as `computed`, so the caller
     * can tell an official figure from an estimate.
     *
     * @return array{rate: float, source: string}|null
     */
    public function getAnnualAverageRate(string $currency, int $year): ?array
    {
        $currency = strtoupper(trim($currency));
        if ($currency === 'RON' || $currency === '') {
            return ['rate' => 1.0, 'source' => 'ron'];
        }
        if ($year < 2005 || $year > (int) date('Y')) {
            return null;
        }

        $complete = $year < (int) date('Y');
        $cacheKey = sprintf('bnr_annual_average_%s_%d', $currency, $year);
        $cached = $this->cache->get($cacheKey, function (ItemInterface $item) use ($currency, $year, $complete) {
            // A closed year never changes; the running one is refreshed daily.
            $item->expiresAfter($complete ? self::LAST_GOOD_TTL : self::FRESH_TTL);
            $monthly = $this->monthlyAveragesFromBnr($currency, $year);
            if ($monthly === []) {
                $item->expiresAfter(self::STALE_RETRY_TTL);

                return null;
            }

            return ['months' => count($monthly), 'rate' => round(array_sum($monthly) / count($monthly), 4)];
        });

        if (!is_array($cached)) {
            return null;
        }
        if ($complete && $cached['months'] < 12) {
            return null;
        }

        return ['rate' => $cached['rate'], 'source' => 'bnr', 'months' => $cached['months']];
    }

    /**
     * The mean rate of each month of a year, from BNR's own file for that year. BNR computes
     * the annual average as the simple mean of these monthly means, which is why the yearly
     * figure differs slightly from the mean of all daily rates.
     *
     * @return list<float>
     */
    private function monthlyAveragesFromBnr(string $currency, int $year): array
    {
        try {
            $response = $this->httpClient->request('GET', sprintf(self::BNR_YEAR_URL, $year), [
                'timeout' => 20,
                'verify_peer' => false,
                'verify_host' => false,
            ]);
            $xml = new \SimpleXMLElement($response->getContent());
        } catch (\Throwable $e) {
            $this->logger->error('[BNR] Failed to load the yearly exchange rates', ['year' => $year, 'error' => $e->getMessage()]);

            return [];
        }

        $perMonth = [];
        foreach ($xml->Body->Cube as $cube) {
            $date = (string) ($cube->attributes()['date'] ?? '');
            if ($date === '') {
                continue;
            }
            foreach ($cube->children() as $rate) {
                if ((string) ($rate->attributes()['currency'] ?? '') !== $currency) {
                    continue;
                }
                $multiplier = (int) ($rate->attributes()['multiplier'] ?? 1);
                $value = (float) $rate;
                if ($value > 0.0) {
                    $perMonth[substr($date, 0, 7)][] = $value / max(1, $multiplier);
                }
            }
        }
        ksort($perMonth);

        return array_values(array_map(static fn (array $days) => array_sum($days) / count($days), $perMonth));
    }

    /**
     * ECB reference rates (1 EUR = x units) per series and day, from the ECB data portal
     * (CSV), cached per window.
     *
     * @param list<string> $series ISO codes, e.g. ['RON', 'USD']
     * @return array<string, array<string, float>>|null [currency => [Y-m-d => value]]
     */
    private function fetchEcbWindow(array $series, \DateTimeImmutable $start, \DateTimeImmutable $end): ?array
    {
        $key = sprintf('ecb_rates_%s_%s_%s', implode('_', $series), $start->format('Ymd'), $end->format('Ymd'));
        $immutable = $end < new \DateTimeImmutable('today');

        return $this->cache->get($key, function (ItemInterface $item) use ($series, $start, $end, $immutable): ?array {
            $item->expiresAfter($immutable ? self::LAST_GOOD_TTL : 3600);
            try {
                $response = $this->httpClient->request('GET', sprintf(self::ECB_URL, implode('+', $series)), [
                    'timeout' => 10,
                    'query' => ['startPeriod' => $start->format('Y-m-d'), 'endPeriod' => $end->format('Y-m-d'), 'format' => 'csvdata'],
                ]);
                $csv = $response->getContent();
            } catch (\Throwable $e) {
                $this->logger->error('[ECB] Failed to load reference rates', ['error' => $e->getMessage()]);
                $item->expiresAfter(self::STALE_RETRY_TTL);
                return null;
            }

            $lines = preg_split('/\r?\n/', trim($csv)) ?: [];
            $header = str_getcsv(array_shift($lines) ?? '');
            $iCur = array_search('CURRENCY', $header, true);
            $iDate = array_search('TIME_PERIOD', $header, true);
            $iVal = array_search('OBS_VALUE', $header, true);
            if ($iCur === false || $iDate === false || $iVal === false) {
                $item->expiresAfter(self::STALE_RETRY_TTL);
                return null;
            }
            $table = [];
            foreach ($lines as $line) {
                $cols = str_getcsv($line);
                $value = $cols[$iVal] ?? '';
                if (!isset($cols[$iCur], $cols[$iDate]) || !is_numeric($value) || (float) $value <= 0) {
                    continue;
                }
                $table[strtoupper($cols[$iCur])][$cols[$iDate]] = (float) $value;
            }

            return $table;
        });
    }

    /**
     * Convert an amount from one currency to RON.
     *
     * @return array{amount: string, rate: string, from: string, to: string}
     */
    public function convertToRon(float $amount, string $fromCurrency): array
    {
        $rate = $this->getRate($fromCurrency);

        if ($rate === null) {
            throw new \RuntimeException(sprintf('Currency %s not supported by BNR.', $fromCurrency));
        }

        $converted = $amount * $rate;

        return [
            'amount' => number_format($converted, 2, '.', ''),
            'rate' => number_format($rate, 4, '.', ''),
            'from' => strtoupper($fromCurrency),
            'to' => 'RON',
        ];
    }

    /**
     * Convert an amount between two currencies via RON.
     *
     * @return array{amount: string, rate: string, from: string, to: string}
     */
    public function convert(float $amount, string $from, string $to): array
    {
        $from = strtoupper($from);
        $to = strtoupper($to);

        if ($from === $to) {
            return [
                'amount' => number_format($amount, 2, '.', ''),
                'rate' => '1.0000',
                'from' => $from,
                'to' => $to,
            ];
        }

        $fromRate = $this->getRate($from);
        $toRate = $this->getRate($to);

        if ($fromRate === null) {
            throw new \RuntimeException(sprintf('Currency %s not supported.', $from));
        }
        if ($toRate === null) {
            throw new \RuntimeException(sprintf('Currency %s not supported.', $to));
        }

        // Convert: amount in FROM -> RON -> TO
        $amountInRon = $amount * $fromRate;
        $converted = $amountInRon / $toRate;
        $crossRate = $fromRate / $toRate;

        return [
            'amount' => number_format($converted, 2, '.', ''),
            'rate' => number_format($crossRate, 4, '.', ''),
            'from' => $from,
            'to' => $to,
        ];
    }

    /**
     * Build a SQL CASE expression that resolves a BNR fallback rate for each distinct
     * currency in a company table. Used as the COALESCE fallback when exchange_rate is NULL.
     *
     * @param string $currencyColumn Column/alias referencing the currency (e.g. 'currency', 'i.currency')
     * @param string $table          Source table to scan for distinct currencies
     */
    public function buildFallbackRateSql(Connection $conn, string $companyId, string $defaultCurrency, string $currencyColumn = 'currency', string $table = 'invoice'): string
    {
        $fallbackRateSql = '1';
        try {
            $distinctCurrencies = $conn->fetchFirstColumn(
                "SELECT DISTINCT currency FROM $table WHERE company_id = :companyId AND deleted_at IS NULL AND currency != :defaultCurrency",
                ['companyId' => $companyId, 'defaultCurrency' => $defaultCurrency]
            );
            if ($distinctCurrencies) {
                $cases = [];
                foreach ($distinctCurrencies as $cur) {
                    $bnrRate = $this->getRate($cur);
                    if ($bnrRate !== null) {
                        $cases[] = sprintf("WHEN %s = '%s' THEN %s", $currencyColumn, addslashes($cur), $bnrRate);
                    }
                }
                if ($cases) {
                    $fallbackRateSql = 'CASE ' . implode(' ', $cases) . ' ELSE 1 END';
                }
            }
        } catch (\Throwable) {
            // BNR unavailable — fall back to 1 (no conversion)
        }

        return $fallbackRateSql;
    }

    /**
     * @return array{date: string, rates: array<string, array{value: float, multiplier: int}>}|null
     */
    private function fetchFromBnr(): ?array
    {
        try {
            // BNR's TLS chain occasionally trips outdated CA bundles in Linux
            // containers ("certificate has expired" even when the browser is
            // happy). The endpoint is public read-only, no auth, no PII —
            // skipping verification has no security cost here.
            $response = $this->httpClient->request('GET', self::BNR_URL, [
                'timeout' => 10,
                'verify_peer' => false,
                'verify_host' => false,
            ]);
            $xml = new \SimpleXMLElement($response->getContent());
        } catch (\Throwable $e) {
            $this->logger->error('[BNR] Failed to load exchange rates', ['error' => $e->getMessage()]);
            return null;
        }

        $date = (string) $xml->Body->Cube->attributes()['date'];
        $rates = [];

        foreach ($xml->Body->Cube->children() as $rate) {
            $currency = (string) $rate->attributes()['currency'];
            $value = (float) $rate;
            $multiplier = (int) ($rate->attributes()['multiplier'] ?? 1);

            $rates[$currency] = [
                'value' => $value,
                'multiplier' => $multiplier,
            ];
        }

        $this->logger->info('[BNR] Exchange rates loaded', ['date' => $date, 'count' => count($rates)]);

        return [
            'date' => $date,
            'rates' => $rates,
        ];
    }

    private function storeLastGood(array $rates): void
    {
        $item = $this->cachePool->getItem(self::LAST_GOOD_KEY);
        $item->set($rates);
        $item->expiresAfter(self::LAST_GOOD_TTL);
        $this->cachePool->save($item);
    }

    /**
     * @return array{date: string, rates: array<string, array{value: float, multiplier: int}>}|null
     */
    private function loadLastGood(): ?array
    {
        $item = $this->cachePool->getItem(self::LAST_GOOD_KEY);
        return $item->isHit() ? $item->get() : null;
    }

    /**
     * Log a CRITICAL line once per day so monitoring (Sentry, log aggregation)
     * can alert without being spammed every time someone hits an invoice page.
     */
    private function logFailureOnce(string $message): void
    {
        $key = 'bnr_failure_critical_' . date('Y-m-d');
        $sentinel = $this->cachePool->getItem($key);
        if ($sentinel->isHit()) {
            return;
        }
        $sentinel->set(true);
        $sentinel->expiresAfter(self::FAILURE_NOTIFICATION_TTL);
        $this->cachePool->save($sentinel);
        $this->logger->critical('[BNR] ' . $message);
    }
}
