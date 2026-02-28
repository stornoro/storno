<?php

namespace App\Service\EInvoice\Germany;

use App\DTO\EInvoice\StatusResponse;
use App\DTO\EInvoice\SubmitResponse;
use App\Enum\EInvoiceSubmissionStatus;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * ZRE (Zentraler Rechnungseingang des Bundes) API client for XRechnung B2G submission.
 *
 * Prod: https://xrechnung.bund.de
 * Test: https://xrechnung-test.bund.de
 *
 * Auth: OAuth2 client credentials → Bearer token
 */
class XRechnungClient
{
    private const BASE_URL_PROD = 'https://xrechnung.bund.de/api/v1';
    private const BASE_URL_TEST = 'https://xrechnung-test.bund.de/api/v1';
    private const TOKEN_URL_PROD = 'https://xrechnung.bund.de/api/v1/auth/token';
    private const TOKEN_URL_TEST = 'https://xrechnung-test.bund.de/api/v1/auth/token';

    private readonly string $baseUrl;
    private readonly string $tokenUrl;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.environment%')]
        string $environment = 'prod',
    ) {
        $this->baseUrl = $environment === 'prod' ? self::BASE_URL_PROD : self::BASE_URL_TEST;
        $this->tokenUrl = $environment === 'prod' ? self::TOKEN_URL_PROD : self::TOKEN_URL_TEST;
    }

    /**
     * Submit an XRechnung XML to ZRE.
     *
     * @param array{clientId: string, clientSecret: string} $credentials OAuth2 credentials from CompanyEInvoiceConfig
     */
    public function submit(string $xml, array $credentials): SubmitResponse
    {
        try {
            $accessToken = $this->authenticate($credentials['clientId'], $credentials['clientSecret']);

            $response = $this->httpClient->request('POST', $this->baseUrl . '/invoices', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/xml',
                    'Accept' => 'application/json',
                ],
                'body' => $xml,
            ]);

            $statusCode = $response->getStatusCode();
            $content = json_decode($response->getContent(false), true) ?? [];

            if ($statusCode >= 200 && $statusCode < 300) {
                return new SubmitResponse(
                    success: true,
                    externalId: $content['id'] ?? $content['invoiceId'] ?? null,
                    metadata: $content,
                );
            }

            return new SubmitResponse(
                success: false,
                errorMessage: $content['message'] ?? $content['error'] ?? "ZRE returned HTTP {$statusCode}",
                metadata: $content,
            );
        } catch (\Throwable $e) {
            $this->logger->error('XRechnungClient: Submission failed.', [
                'error' => $e->getMessage(),
            ]);

            return new SubmitResponse(
                success: false,
                errorMessage: $e->getMessage(),
            );
        }
    }

    /**
     * Check the processing status of a submitted invoice.
     */
    public function checkStatus(string $externalId, array $credentials): StatusResponse
    {
        try {
            $accessToken = $this->authenticate($credentials['clientId'], $credentials['clientSecret']);

            $response = $this->httpClient->request('GET', $this->baseUrl . '/invoices/' . $externalId . '/status', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Accept' => 'application/json',
                ],
            ]);

            $content = json_decode($response->getContent(false), true) ?? [];
            $status = $this->mapZreStatus($content['status'] ?? 'unknown');

            return new StatusResponse(
                status: $status,
                errorMessage: $content['errorMessage'] ?? $content['rejectionReason'] ?? null,
                metadata: $content,
            );
        } catch (\Throwable $e) {
            $this->logger->error('XRechnungClient: Status check failed.', [
                'externalId' => $externalId,
                'error' => $e->getMessage(),
            ]);

            return new StatusResponse(
                status: EInvoiceSubmissionStatus::ERROR,
                errorMessage: $e->getMessage(),
            );
        }
    }

    private function authenticate(string $clientId, string $clientSecret): string
    {
        $response = $this->httpClient->request('POST', $this->tokenUrl, [
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => [
                'grant_type' => 'client_credentials',
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
            ],
        ]);

        $data = json_decode($response->getContent(false), true);

        if (!isset($data['access_token'])) {
            throw new \RuntimeException('ZRE OAuth2 authentication failed: ' . ($data['error_description'] ?? $data['error'] ?? 'Unknown error'));
        }

        return $data['access_token'];
    }

    private function mapZreStatus(string $zreStatus): EInvoiceSubmissionStatus
    {
        return match (strtolower($zreStatus)) {
            'received', 'processing' => EInvoiceSubmissionStatus::PENDING,
            'delivered', 'accepted' => EInvoiceSubmissionStatus::ACCEPTED,
            'rejected' => EInvoiceSubmissionStatus::REJECTED,
            default => EInvoiceSubmissionStatus::PENDING,
        };
    }
}
