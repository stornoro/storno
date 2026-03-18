<?php

namespace App\Service\Vies;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ViesService
{
    private const VIES_API_URL = 'https://ec.europa.eu/taxation_customs/vies/rest-api//check-vat-number';
    private const TIMEOUT = 10;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Validate a VAT number against the VIES API.
     *
     * @return array{valid: bool, name: string|null, address: string|null}|null
     */
    public function validate(string $countryCode, string $vatNumber): ?array
    {
        try {
            $response = $this->httpClient->request('POST', self::VIES_API_URL, [
                'json' => [
                    'countryCode' => strtoupper($countryCode),
                    'vatNumber' => $vatNumber,
                ],
                'timeout' => self::TIMEOUT,
                'proxy' => $_ENV['VIES_PROXY_URL'] ?? null,
            ]);

            $data = $response->toArray();

            // VIES may return an error (e.g. MS_UNAVAILABLE) — treat as inconclusive
            if (isset($data['userError']) || isset($data['actionSucceed']) && $data['actionSucceed'] === false) {
                $this->logger->warning('VIES returned error response', [
                    'countryCode' => $countryCode,
                    'vatNumber' => $vatNumber,
                    'userError' => $data['userError'] ?? null,
                ]);
                return null;
            }

            // Only trust the result if 'valid' key is explicitly present
            if (!array_key_exists('valid', $data)) {
                return null;
            }

            return [
                'valid' => $data['valid'],
                'name' => !empty($data['name']) && $data['name'] !== '---' ? trim($data['name']) : null,
                'address' => !empty($data['address']) && $data['address'] !== '---' ? trim($data['address']) : null,
            ];
        } catch (\Throwable $e) {
            $this->logger->error('VIES validation failed', [
                'countryCode' => $countryCode,
                'vatNumber' => $vatNumber,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Parse a full VAT code (e.g., "DE123456789") into country code and VAT number.
     *
     * @return array{countryCode: string, vatNumber: string}|null
     */
    public function parseVatCode(string $vatCode): ?array
    {
        $vatCode = strtoupper(trim($vatCode));

        if (preg_match('/^([A-Z]{2})(.+)$/', $vatCode, $matches)) {
            return [
                'countryCode' => $matches[1],
                'vatNumber' => $matches[2],
            ];
        }

        return null;
    }
}
