<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ExchangeRateService
{
    private const BNR_URL = 'https://www.bnr.ro/nbrfxrates.xml';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Get all BNR exchange rates for today (cached daily).
     *
     * @return array{date: string, rates: array<string, array{value: float, multiplier: int}>}
     */
    public function getRates(): array
    {
        $cacheKey = 'bnr_exchange_rates_' . date('Y-m-d');

        return $this->cache->get($cacheKey, function (ItemInterface $item) {
            $item->expiresAfter(86400);

            return $this->fetchFromBnr();
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

    private function fetchFromBnr(): array
    {
        try {
            $response = $this->httpClient->request('GET', self::BNR_URL);
            $xml = new \SimpleXMLElement($response->getContent());
        } catch (\Throwable $e) {
            $this->logger->error('[BNR] Failed to load exchange rates', ['error' => $e->getMessage()]);
            throw new \RuntimeException('Unable to fetch BNR rates: ' . $e->getMessage());
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
}
