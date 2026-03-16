<?php

namespace App\Service\Declaration;

use App\Entity\AnafToken;
use App\Service\Anaf\AnafCertificateResolver;
use App\Service\Anaf\AnafRateLimiter;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Client for ANAF SPV (Spatiul Virtual Privat) declaration submission API.
 *
 * Uses the SPVWS2 REST API at webserviced.anaf.ro — different from the
 * e-Factura FCTEL API used for invoice submission.
 *
 * Flow:
 *  1. cerere?tip=D394&cui=... → initiates a submission request, returns an ID
 *  2. listaMesaje?zile=N      → lists recent SPV messages (status updates)
 *  3. descarcare?id=...       → downloads recipisa/response PDF
 */
class AnafDeclarationClient
{
    private const BASE_URL = 'https://webserviced.anaf.ro/SPVWS2/rest';
    private const EPATRIM_D112_URL = 'https://epatrim.anaf.ro/StareD112';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly AnafRateLimiter $rateLimiter,
        private readonly AnafCertificateResolver $certificateResolver,
    ) {}

    /**
     * Submit a declaration XML to ANAF SPV.
     *
     * @return array{id_solicitare?: string, ...} Response from ANAF
     */
    public function upload(string $xml, string $cif, string $token, string $declarationType, ?AnafToken $anafToken = null): array
    {
        $this->rateLimiter->consumeGlobal();

        $type = strtoupper($declarationType); // D394, D300, D390, etc.

        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/xml',
            ],
            'query' => [
                'tip' => $type,
                'cui' => $cif,
            ],
            'body' => $xml,
        ];

        $certInfo = $this->resolveCertOptions($anafToken);
        if ($certInfo !== null) {
            $options['local_cert'] = $certInfo['certPath'];
            $options['passphrase'] = $certInfo['passphrase'];
        }

        $response = $this->httpClient->request('POST', self::BASE_URL . '/cerere', $options);

        $statusCode = $response->getStatusCode();
        $content = $response->getContent(false);

        $this->cleanupCert($certInfo);

        $this->checkForAuthError($statusCode, 'upload');

        if ($statusCode >= 400) {
            throw new \RuntimeException(sprintf(
                'ANAF SPV declaration upload failed: HTTP %d - %s',
                $statusCode,
                substr($content, 0, 500),
            ));
        }

        return $this->parseResponse($content);
    }

    /**
     * List recent SPV messages to check declaration processing status.
     *
     * @return array SPV messages list
     */
    public function listMessages(string $token, int $days = 60, ?AnafToken $anafToken = null): array
    {
        $this->rateLimiter->consumeGlobal();

        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
            ],
            'query' => [
                'zile' => $days,
            ],
        ];

        $certInfo = $this->resolveCertOptions($anafToken);
        if ($certInfo !== null) {
            $options['local_cert'] = $certInfo['certPath'];
            $options['passphrase'] = $certInfo['passphrase'];
        }

        $response = $this->httpClient->request('GET', self::BASE_URL . '/listaMesaje', $options);

        $statusCode = $response->getStatusCode();
        $content = $response->getContent(false);

        $this->cleanupCert($certInfo);
        $this->checkForAuthError($statusCode, 'listMessages');

        return $this->parseResponse($content);
    }

    /**
     * Check the status of a specific declaration by looking up its upload ID
     * in the SPV messages list.
     */
    public function checkStatus(string $uploadId, string $token, ?AnafToken $anafToken = null): array
    {
        $messages = $this->listMessages($token, 60, $anafToken);

        // Look for a message matching our upload ID
        if (isset($messages['mesaje']) && is_array($messages['mesaje'])) {
            foreach ($messages['mesaje'] as $message) {
                if (isset($message['id_solicitare']) && (string) $message['id_solicitare'] === $uploadId) {
                    return $message;
                }
            }
        }

        return ['status' => 'processing', 'raw' => $messages];
    }

    /**
     * Download a document (recipisa PDF) from ANAF SPV.
     */
    public function downloadRecipisa(string $id, string $token, ?AnafToken $anafToken = null): string
    {
        $this->rateLimiter->consumeGlobal();

        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
            ],
            'query' => [
                'id' => $id,
            ],
        ];

        $certInfo = $this->resolveCertOptions($anafToken);
        if ($certInfo !== null) {
            $options['local_cert'] = $certInfo['certPath'];
            $options['passphrase'] = $certInfo['passphrase'];
        }

        $response = $this->httpClient->request('GET', self::BASE_URL . '/descarcare', $options);

        $statusCode = $response->getStatusCode();
        $content = $response->getContent(false);

        $this->cleanupCert($certInfo);
        $this->checkForAuthError($statusCode, 'downloadRecipisa');

        if ($statusCode >= 400) {
            throw new \RuntimeException(sprintf(
                'ANAF SPV recipisa download failed: HTTP %d',
                $statusCode,
            ));
        }

        return $content;
    }

    /**
     * List recent SPV messages filtered by CIF.
     */
    public function listMessagesByCif(string $token, string $cif, int $days = 60, ?AnafToken $anafToken = null): array
    {
        $this->rateLimiter->consumeGlobal();

        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
            ],
            'query' => [
                'zile' => $days,
                'cif' => $cif,
            ],
        ];

        $certInfo = $this->resolveCertOptions($anafToken);
        if ($certInfo !== null) {
            $options['local_cert'] = $certInfo['certPath'];
            $options['passphrase'] = $certInfo['passphrase'];
        }

        $response = $this->httpClient->request('GET', self::BASE_URL . '/listaMesaje', $options);

        $statusCode = $response->getStatusCode();
        $content = $response->getContent(false);

        $this->cleanupCert($certInfo);
        $this->checkForAuthError($statusCode, 'listMessagesByCif');

        return $this->parseResponse($content);
    }

    /**
     * Request a read-only report from ANAF SPV (e.g. "Istoric declaratii").
     */
    public function requestReport(string $token, string $cif, string $tip, ?int $year = null, ?int $month = null, ?AnafToken $anafToken = null): array
    {
        $this->rateLimiter->consumeGlobal();

        $query = [
            'tip' => $tip,
            'cui' => $cif,
        ];

        if ($year !== null) {
            $query['an'] = $year;
        }
        if ($month !== null) {
            $query['luna'] = $month;
        }

        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
            ],
            'query' => $query,
        ];

        $certInfo = $this->resolveCertOptions($anafToken);
        if ($certInfo !== null) {
            $options['local_cert'] = $certInfo['certPath'];
            $options['passphrase'] = $certInfo['passphrase'];
        }

        $response = $this->httpClient->request('GET', self::BASE_URL . '/cerere', $options);

        $statusCode = $response->getStatusCode();
        $content = $response->getContent(false);

        $this->cleanupCert($certInfo);
        $this->checkForAuthError($statusCode, 'requestReport');

        return $this->parseResponse($content);
    }

    /**
     * Check D112 status via the legacy epatrim.anaf.ro endpoint.
     *
     * This is a separate system from SPV used specifically for D112.
     * 4secunde uses this as a fallback when SPV doesn't have the status yet.
     */
    public function checkD112Status(string $token, ?AnafToken $anafToken = null): array
    {
        $this->rateLimiter->consumeGlobal();

        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
            ],
        ];

        $certInfo = $this->resolveCertOptions($anafToken);
        if ($certInfo !== null) {
            $options['local_cert'] = $certInfo['certPath'];
            $options['passphrase'] = $certInfo['passphrase'];
        }

        $response = $this->httpClient->request('GET', self::EPATRIM_D112_URL . '/vizualizareStare.do', $options);

        $statusCode = $response->getStatusCode();
        $content = $response->getContent(false);

        $this->cleanupCert($certInfo);
        $this->checkForAuthError($statusCode, 'checkD112Status');

        return $this->parseResponse($content);
    }

    /**
     * Download D112 recipisa via the legacy epatrim.anaf.ro endpoint.
     *
     * @param string $filename The filename returned by the D112 status endpoint
     */
    public function downloadD112Recipisa(string $filename, string $token, ?AnafToken $anafToken = null): string
    {
        $this->rateLimiter->consumeGlobal();

        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
            ],
            'query' => [
                'numefisier' => $filename,
            ],
        ];

        $certInfo = $this->resolveCertOptions($anafToken);
        if ($certInfo !== null) {
            $options['local_cert'] = $certInfo['certPath'];
            $options['passphrase'] = $certInfo['passphrase'];
        }

        $response = $this->httpClient->request('GET', self::EPATRIM_D112_URL . '/ObtineRecipisa', $options);

        $statusCode = $response->getStatusCode();
        $content = $response->getContent(false);

        $this->cleanupCert($certInfo);
        $this->checkForAuthError($statusCode, 'downloadD112Recipisa');

        if ($statusCode >= 400) {
            throw new \RuntimeException(sprintf(
                'ANAF D112 recipisa download failed: HTTP %d',
                $statusCode,
            ));
        }

        return $content;
    }

    /**
     * Throw AnafTokenExpiredException on 401/403 so handlers can retry with a fresh token.
     */
    private function checkForAuthError(int $statusCode, string $operation): void
    {
        if ($statusCode === 401 || $statusCode === 403) {
            throw new AnafTokenExpiredException(sprintf(
                'ANAF SPV returned HTTP %d during %s — token may be expired.',
                $statusCode,
                $operation,
            ));
        }
    }

    /**
     * @return array{certPath: string, passphrase: string}|null
     */
    private function resolveCertOptions(?AnafToken $anafToken): ?array
    {
        if ($anafToken === null || !$anafToken->hasCertificate()) {
            return null;
        }

        return $this->certificateResolver->resolve($anafToken);
    }

    private function cleanupCert(?array $certInfo): void
    {
        if ($certInfo !== null) {
            $this->certificateResolver->cleanup($certInfo['certPath']);
        }
    }

    private function parseResponse(string $content): array
    {
        $json = json_decode($content, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
            return $json;
        }

        $xml = @simplexml_load_string($content);
        if ($xml !== false) {
            return json_decode(json_encode($xml), true);
        }

        return ['raw' => $content];
    }
}
