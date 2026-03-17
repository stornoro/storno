<?php

namespace App\Service\Declaration;

use App\Model\Declaration\DukValidationResult;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class DukIntegratorService
{
    private string $serviceUrl;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly HttpClientInterface $httpClient,
        string $javaServiceUrl = '',
    ) {
        $this->serviceUrl = $javaServiceUrl ?: 'http://127.0.0.1:8082';
    }

    public function validate(string $xml, string $type): DukValidationResult
    {
        $type = strtoupper($type);

        try {
            $response = $this->httpClient->request('POST', $this->serviceUrl . '/duk/validate', [
                'query' => ['type' => $type],
                'body' => $xml,
                'headers' => ['Content-Type' => 'application/xml'],
                'timeout' => 60,
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode === 200) {
                $data = $response->toArray();
                return new DukValidationResult(
                    valid: $data['valid'] ?? false,
                    errors: $data['errors'] ?? [],
                    warnings: $data['warnings'] ?? [],
                );
            }

            if ($statusCode === 503) {
                throw new \RuntimeException('DUK validation service unavailable.');
            }

            $data = $response->toArray(false);
            return new DukValidationResult(
                valid: false,
                errors: [$data['error'] ?? 'Unknown validation error'],
            );
        } catch (\Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface $e) {
            $this->logger->error('DUK validation service unreachable', [
                'error' => $e->getMessage(),
                'type' => $type,
            ]);
            throw new \RuntimeException('DUK validation service unreachable: ' . $e->getMessage());
        }
    }

    /**
     * Generate an unsigned PDF from XML using DUKIntegrator.
     *
     * @return string PDF binary content
     */
    public function generatePdf(string $xml, string $type): string
    {
        $type = strtoupper($type);

        try {
            $response = $this->httpClient->request('POST', $this->serviceUrl . '/duk/generate-pdf', [
                'query' => ['type' => $type],
                'body' => $xml,
                'headers' => ['Content-Type' => 'application/xml'],
                'timeout' => 60,
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode === 200) {
                return $response->getContent();
            }

            if ($statusCode === 503) {
                throw new \RuntimeException('DUK PDF generation service unavailable.');
            }

            $data = $response->toArray(false);
            throw new \RuntimeException($data['error'] ?? 'DUK PDF generation failed');
        } catch (\Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface $e) {
            $this->logger->error('DUK PDF generation service unreachable', [
                'error' => $e->getMessage(),
                'type' => $type,
            ]);
            throw new \RuntimeException('DUK PDF generation service unreachable: ' . $e->getMessage());
        }
    }

    public function isAvailable(): bool
    {
        try {
            $response = $this->httpClient->request('GET', $this->serviceUrl . '/health', [
                'timeout' => 2,
            ]);
            $data = $response->toArray(false);
            return ($data['duk'] ?? false) === true;
        } catch (\Throwable) {
            return false;
        }
    }
}
