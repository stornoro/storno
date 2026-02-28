<?php

namespace App\Service\EInvoice\Italy;

use App\DTO\EInvoice\StatusResponse;
use App\DTO\EInvoice\SubmitResponse;
use App\Enum\EInvoiceSubmissionStatus;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * SDI (Sistema di Interscambio) API client for Italian e-invoicing.
 *
 * Auth: Client certificate (PKCS#12 / .p12 file) with the digital signature.
 * SDI accepts FatturaPA XML and returns a receipt with invoice ID.
 *
 * Note: Most businesses use an intermediary (e.g., Aruba, Namirial) that wraps SDI.
 * This client supports both direct SDI and common intermediary APIs.
 *
 * @see https://www.fatturapa.gov.it/it/sistemainterscambio/
 */
class SdiClient
{
    private const BASE_URL_PROD = 'https://sdi.fatturapa.it/SdIRiceviFile/v1.0';
    private const BASE_URL_TEST = 'https://testservizi.fatturapa.it/SdIRiceviFile/v1.0';

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
     * Submit a FatturaPA XML to SDI.
     *
     * @param array{certPath: string, certPassword: string, apiEndpoint?: string, apiKey?: string} $credentials
     */
    public function submit(string $xml, string $filename, array $credentials): SubmitResponse
    {
        try {
            // If an intermediary API endpoint is configured, use that instead of direct SDI
            if (!empty($credentials['apiEndpoint'])) {
                return $this->submitViaIntermediary($xml, $filename, $credentials);
            }

            return $this->submitDirect($xml, $filename, $credentials);
        } catch (\Throwable $e) {
            $this->logger->error('SdiClient: Submission failed.', [
                'filename' => $filename,
                'error' => $e->getMessage(),
            ]);

            return new SubmitResponse(
                success: false,
                errorMessage: $e->getMessage(),
            );
        }
    }

    /**
     * Check the status of a submitted invoice.
     *
     * @param array{certPath?: string, certPassword?: string, apiEndpoint?: string, apiKey?: string} $credentials
     */
    public function checkStatus(string $externalId, array $credentials): StatusResponse
    {
        try {
            if (!empty($credentials['apiEndpoint'])) {
                return $this->checkStatusViaIntermediary($externalId, $credentials);
            }

            // Direct SDI status is typically received via callback, not polling.
            // Return pending to indicate async processing.
            return new StatusResponse(
                status: EInvoiceSubmissionStatus::PENDING,
                metadata: ['note' => 'Direct SDI uses callback notifications. Status polling not available.'],
            );
        } catch (\Throwable $e) {
            $this->logger->error('SdiClient: Status check failed.', [
                'externalId' => $externalId,
                'error' => $e->getMessage(),
            ]);

            return new StatusResponse(
                status: EInvoiceSubmissionStatus::ERROR,
                errorMessage: $e->getMessage(),
            );
        }
    }

    /**
     * Direct submission to SDI via SOAP/MTOM with client certificate.
     */
    private function submitDirect(string $xml, string $filename, array $credentials): SubmitResponse
    {
        $soapBody = $this->buildSoapEnvelope($xml, $filename);

        $options = [
            'headers' => [
                'Content-Type' => 'text/xml; charset=utf-8',
                'SOAPAction' => 'http://www.fatturapa.it/SdIRiceviFile/RiceviFile',
            ],
            'body' => $soapBody,
            'local_cert' => $credentials['certPath'],
            'passphrase' => $credentials['certPassword'] ?? '',
        ];

        $response = $this->httpClient->request('POST', $this->baseUrl, $options);
        $content = $response->getContent(false);
        $statusCode = $response->getStatusCode();

        if ($statusCode >= 200 && $statusCode < 300) {
            $parsed = $this->parseSoapResponse($content);
            return new SubmitResponse(
                success: true,
                externalId: $parsed['identificativoSdI'] ?? null,
                metadata: $parsed,
            );
        }

        return new SubmitResponse(
            success: false,
            errorMessage: "SDI returned HTTP {$statusCode}: " . mb_substr($content, 0, 500),
        );
    }

    /**
     * Submission via intermediary REST API (Aruba, Namirial, etc.).
     */
    private function submitViaIntermediary(string $xml, string $filename, array $credentials): SubmitResponse
    {
        $response = $this->httpClient->request('POST', $credentials['apiEndpoint'] . '/invoices', [
            'headers' => [
                'Authorization' => 'Bearer ' . ($credentials['apiKey'] ?? ''),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'json' => [
                'filename' => $filename,
                'xml' => base64_encode($xml),
            ],
        ]);

        $statusCode = $response->getStatusCode();
        $data = json_decode($response->getContent(false), true) ?? [];

        if ($statusCode >= 200 && $statusCode < 300) {
            return new SubmitResponse(
                success: true,
                externalId: $data['id'] ?? $data['identificativoSdI'] ?? null,
                metadata: $data,
            );
        }

        return new SubmitResponse(
            success: false,
            errorMessage: $data['message'] ?? $data['error'] ?? "Intermediary returned HTTP {$statusCode}",
            metadata: $data,
        );
    }

    private function checkStatusViaIntermediary(string $externalId, array $credentials): StatusResponse
    {
        $response = $this->httpClient->request('GET', $credentials['apiEndpoint'] . '/invoices/' . $externalId, [
            'headers' => [
                'Authorization' => 'Bearer ' . ($credentials['apiKey'] ?? ''),
                'Accept' => 'application/json',
            ],
        ]);

        $data = json_decode($response->getContent(false), true) ?? [];
        $status = $this->mapSdiStatus($data['status'] ?? $data['stato'] ?? 'unknown');

        return new StatusResponse(
            status: $status,
            errorMessage: $data['errorMessage'] ?? $data['errore'] ?? null,
            metadata: $data,
        );
    }

    private function buildSoapEnvelope(string $xml, string $filename): string
    {
        $xmlBase64 = base64_encode($xml);

        return <<<SOAP
<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:typ="http://www.fatturapa.gov.it/sdi/ws/trasmissione/v1.0/types">
    <soapenv:Header/>
    <soapenv:Body>
        <typ:fileSdIAccoglienza>
            <NomeFile>{$filename}</NomeFile>
            <File>{$xmlBase64}</File>
        </typ:fileSdIAccoglienza>
    </soapenv:Body>
</soapenv:Envelope>
SOAP;
    }

    private function parseSoapResponse(string $content): array
    {
        $xml = @simplexml_load_string($content);
        if ($xml === false) {
            return ['raw' => mb_substr($content, 0, 1000)];
        }

        // Navigate SOAP envelope to body
        $namespaces = $xml->getNamespaces(true);
        $body = $xml->children($namespaces['soapenv'] ?? 'http://schemas.xmlsoap.org/soap/envelope/')->Body ?? $xml;

        $json = json_encode($body);
        return json_decode($json, true) ?? [];
    }

    private function mapSdiStatus(string $sdiStatus): EInvoiceSubmissionStatus
    {
        return match (strtolower($sdiStatus)) {
            'ricevuta', 'received', 'inviata', 'sent' => EInvoiceSubmissionStatus::PENDING,
            'consegnata', 'delivered', 'accettata', 'accepted' => EInvoiceSubmissionStatus::ACCEPTED,
            'rifiutata', 'rejected', 'scartata', 'discarded' => EInvoiceSubmissionStatus::REJECTED,
            'impossibilita_recapito', 'undeliverable' => EInvoiceSubmissionStatus::ERROR,
            default => EInvoiceSubmissionStatus::PENDING,
        };
    }
}
