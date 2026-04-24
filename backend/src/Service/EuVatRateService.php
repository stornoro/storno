<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class EuVatRateService
{
    private const RATES_URL = 'https://raw.githubusercontent.com/ibericode/vat-rates/master/vat-rates.json';
    private const CACHE_KEY = 'eu_vat_rates';

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Returns the current standard VAT rate for an EU country code (e.g. "HU" → 27.0).
     */
    public function getStandardRate(string $countryCode): ?float
    {
        $allRates = $this->getAllRates($countryCode);
        return $allRates['standard'] ?? null;
    }

    /**
     * Returns all current VAT rates for an EU country code.
     * e.g. "HU" → ['standard' => 27.0, 'reduced1' => 5.0, 'reduced2' => 18.0]
     */
    public function getAllRates(string $countryCode): ?array
    {
        $rates = $this->fetchRates();
        if ($rates === null) {
            return null;
        }

        $countryCode = strtoupper($countryCode);
        if (!isset($rates['items'][$countryCode])) {
            return null;
        }

        $countryData = $rates['items'][$countryCode];
        // The JSON has periods as a direct array under the country code
        $periods = isset($countryData[0]) ? $countryData : ($countryData['periods'] ?? []);
        $today = date('Y-m-d');

        // Periods are sorted newest-first in the JSON; find the first one effective today or earlier
        foreach ($periods as $period) {
            if (($period['effective_from'] ?? '') <= $today) {
                return $period['rates'] ?? null;
            }
        }

        return null;
    }

    /**
     * Force-refresh the cached rates from the GitHub source. Intended to be called
     * by a monthly scheduled command. On failure the existing cached rates are kept
     * untouched so invoices continue to apply the last-known values.
     */
    public function refresh(): bool
    {
        try {
            $response = $this->httpClient->request('GET', self::RATES_URL, [
                'timeout' => 30,
            ]);
            $data = $response->toArray();
        } catch (\Throwable $e) {
            $this->logger->warning('EU VAT rates refresh failed; keeping existing cache.', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }

        $this->cache->delete(self::CACHE_KEY);
        $this->cache->get(self::CACHE_KEY, static fn (ItemInterface $item): array => $data);

        return true;
    }

    private function fetchRates(): ?array
    {
        return $this->cache->get(self::CACHE_KEY, function (ItemInterface $item): ?array {
            // Bootstrap path: first-ever access (or after cache flush). Normally
            // the cache is populated by the monthly `app:vat-rates:sync` command.
            try {
                $response = $this->httpClient->request('GET', self::RATES_URL, [
                    'timeout' => 10,
                ]);

                return $response->toArray();
            } catch (\Throwable $e) {
                // Short TTL so next request retries quickly — once the scheduled
                // sync succeeds, the entry becomes permanent.
                $item->expiresAfter(300);
                $this->logger->warning('EU VAT rates bootstrap fetch failed.', [
                    'error' => $e->getMessage(),
                ]);
                return null;
            }
        });
    }
}
