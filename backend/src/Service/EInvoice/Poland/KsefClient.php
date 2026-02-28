<?php

namespace App\Service\EInvoice\Poland;

use App\DTO\EInvoice\StatusResponse;
use App\DTO\EInvoice\SubmitResponse;
use App\Enum\EInvoiceSubmissionStatus;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * KSeF (Krajowy System e-Faktur) REST API client for Polish e-invoicing.
 *
 * Prod: https://ksef.mf.gov.pl/api/
 * Test: https://ksef-test.mf.gov.pl/api/
 *
 * Auth flow:
 * 1. POST /online/Session/InitSigned — init session with signed token
 * 2. Response includes sessionToken for subsequent requests
 * 3. POST /online/Invoice/Send — submit invoice within session
 * 4. GET /common/Invoice/KSeF/{ksefReferenceNumber} — retrieve by KSeF number
 *
 * @see https://www.podatki.gov.pl/ksef/
 */
class KsefClient
{
    private const BASE_URL_PROD = 'https://ksef.mf.gov.pl/api';
    private const BASE_URL_TEST = 'https://ksef-test.mf.gov.pl/api';

    private readonly string $baseUrl;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.environment%')]
        string $environment = 'prod',
    ) {
        $this->baseUrl = $environment === 'prod' ? self::BASE_URL_PROD : self::BASE_URL_TEST;
    }

    /**
     * Submit an invoice to KSeF.
     *
     * @param array{authToken: string, nip: string} $credentials From CompanyEInvoiceConfig
     */
    public function submit(string $xml, array $credentials): SubmitResponse
    {
        try {
            // Step 1: Initialize session
            $sessionToken = $this->initSession($credentials['authToken'], $credentials['nip']);

            // Step 2: Send invoice
            $response = $this->httpClient->request('PUT', $this->baseUrl . '/online/Invoice/Send', [
                'headers' => [
                    'SessionToken' => $sessionToken,
                    'Content-Type' => 'application/octet-stream',
                    'Accept' => 'application/json',
                ],
                'body' => $xml,
            ]);

            $statusCode = $response->getStatusCode();
            $data = json_decode($response->getContent(false), true) ?? [];

            // Step 3: Terminate session (best effort)
            $this->terminateSession($sessionToken);

            if ($statusCode >= 200 && $statusCode < 300) {
                $ksefNumber = $data['invoiceStatus']['ksefReferenceNumber']
                    ?? $data['elementReferenceNumber']
                    ?? null;

                return new SubmitResponse(
                    success: true,
                    externalId: $ksefNumber,
                    metadata: $data,
                );
            }

            return new SubmitResponse(
                success: false,
                errorMessage: $data['exception']['exceptionDetailList'][0]['exceptionDescription']
                    ?? $data['message']
                    ?? "KSeF returned HTTP {$statusCode}",
                metadata: $data,
            );
        } catch (\Throwable $e) {
            $this->logger->error('KsefClient: Submission failed.', [
                'error' => $e->getMessage(),
            ]);

            return new SubmitResponse(
                success: false,
                errorMessage: $e->getMessage(),
            );
        }
    }

    /**
     * Check status of a submitted invoice by its KSeF reference number.
     */
    public function checkStatus(string $ksefReferenceNumber, array $credentials): StatusResponse
    {
        try {
            $response = $this->httpClient->request('GET', $this->baseUrl . '/common/Status/' . $ksefReferenceNumber, [
                'headers' => [
                    'Accept' => 'application/json',
                ],
            ]);

            $data = json_decode($response->getContent(false), true) ?? [];
            $processingCode = $data['processingCode'] ?? 0;

            // KSeF processing codes:
            // 200 = accepted, 400 = rejected/error
            $status = match (true) {
                $processingCode >= 200 && $processingCode < 300 => EInvoiceSubmissionStatus::ACCEPTED,
                $processingCode >= 400 => EInvoiceSubmissionStatus::REJECTED,
                default => EInvoiceSubmissionStatus::PENDING,
            };

            return new StatusResponse(
                status: $status,
                errorMessage: $data['processingDescription'] ?? null,
                metadata: $data,
            );
        } catch (\Throwable $e) {
            $this->logger->error('KsefClient: Status check failed.', [
                'ksefReferenceNumber' => $ksefReferenceNumber,
                'error' => $e->getMessage(),
            ]);

            return new StatusResponse(
                status: EInvoiceSubmissionStatus::ERROR,
                errorMessage: $e->getMessage(),
            );
        }
    }

    /**
     * Retrieve a full invoice from KSeF by reference number.
     */
    public function download(string $ksefReferenceNumber, array $credentials): ?string
    {
        try {
            $sessionToken = $this->initSession($credentials['authToken'], $credentials['nip']);

            $response = $this->httpClient->request('GET', $this->baseUrl . '/online/Invoice/Get/' . $ksefReferenceNumber, [
                'headers' => [
                    'SessionToken' => $sessionToken,
                    'Accept' => 'application/octet-stream',
                ],
            ]);

            $content = $response->getContent(false);

            $this->terminateSession($sessionToken);

            return $response->getStatusCode() < 300 ? $content : null;
        } catch (\Throwable $e) {
            $this->logger->error('KsefClient: Download failed.', [
                'ksefReferenceNumber' => $ksefReferenceNumber,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Initialize a KSeF session with the auth token.
     */
    private function initSession(string $authToken, string $nip): string
    {
        $initPayload = [
            'context' => [
                'challenge' => bin2hex(random_bytes(16)),
                'identifier' => [
                    'type' => 'onip',
                    'identifier' => $nip,
                ],
            ],
        ];

        $response = $this->httpClient->request('POST', $this->baseUrl . '/online/Session/InitToken', [
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'json' => $initPayload,
        ]);

        $data = json_decode($response->getContent(false), true) ?? [];

        // The init response includes an encrypted challenge that must be signed with the auth token
        // For token-based auth (most common), we use InitToken endpoint
        $sessionToken = $data['sessionToken']['token'] ?? null;

        if ($sessionToken === null) {
            throw new \RuntimeException(
                'KSeF session initialization failed: ' . ($data['exception']['exceptionDetailList'][0]['exceptionDescription'] ?? 'No session token returned')
            );
        }

        return $sessionToken;
    }

    private function terminateSession(string $sessionToken): void
    {
        try {
            $this->httpClient->request('GET', $this->baseUrl . '/online/Session/Terminate', [
                'headers' => [
                    'SessionToken' => $sessionToken,
                    'Accept' => 'application/json',
                ],
            ]);
        } catch (\Throwable $e) {
            // Best effort — don't fail the submission if session termination fails
            $this->logger->warning('KsefClient: Session termination failed.', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
