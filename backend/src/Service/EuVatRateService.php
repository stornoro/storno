<?php

namespace App\Service;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class EuVatRateService
{
    private const RATES_URL = 'https://raw.githubusercontent.com/ibericode/vat-rates/master/vat-rates.json';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
    ) {}

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

    private function fetchRates(): ?array
    {
        return $this->cache->get('eu_vat_rates', function (ItemInterface $item): ?array {
            $item->expiresAfter(86400); // 1 day

            try {
                $response = $this->httpClient->request('GET', self::RATES_URL, [
                    'timeout' => 10,
                ]);

                return $response->toArray();
            } catch (\Throwable) {
                $item->expiresAfter(300); // retry sooner on failure
                return null;
            }
        });
    }
}
