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
    private const BNR_URL = 'https://www.bnr.ro/nbrfxrates.xml';
    private const LAST_GOOD_KEY = 'bnr_exchange_rates_last_good';
    private const LAST_GOOD_TTL = 86400 * 365; // ~1 year — effectively persistent
    private const FRESH_TTL = 86400; // 24h cache for successful fetches
    private const STALE_RETRY_TTL = 600; // 10min retry window when serving fallback / empty
    private const FAILURE_NOTIFICATION_TTL = 86400; // dedupe critical warning to once per day

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
            $response = $this->httpClient->request('GET', self::BNR_URL, ['timeout' => 10]);
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
