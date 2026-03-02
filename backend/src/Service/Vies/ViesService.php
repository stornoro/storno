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
            ]);

            $data = $response->toArray();

            return [
                'valid' => $data['valid'] ?? false,
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
