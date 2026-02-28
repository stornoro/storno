<?php

namespace App\Service\EInvoice\France;

use App\DTO\EInvoice\StatusResponse;
use App\DTO\EInvoice\SubmitResponse;
use App\Enum\EInvoiceSubmissionStatus;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Chorus Pro API client for French B2G e-invoicing.
 *
 * Chorus Pro is the French government's e-invoicing portal.
 * For B2B, Factur-X PDF is exchanged directly; Chorus Pro is B2G only.
 *
 * Prod: https://chorus-pro.gouv.fr/api/v1
 * Sandbox: https://sandbox-api.piste.gouv.fr/cpro/factures/v1
 *
 * Auth: OAuth2 via PISTE platform (api.gouv.fr)
 * Token URL: https://sandbox-oauth.piste.gouv.fr/api/oauth/token (sandbox)
 *            https://oauth.piste.gouv.fr/api/oauth/token (prod)
 *
 * @see https://developer.aife.economie.gouv.fr/
 */
class ChorusProClient
{
    private const BASE_URL_PROD = 'https://chorus-pro.gouv.fr/cpro/factures/v1';
    private const BASE_URL_TEST = 'https://sandbox-api.piste.gouv.fr/cpro/factures/v1';
    private const TOKEN_URL_PROD = 'https://oauth.piste.gouv.fr/api/oauth/token';
    private const TOKEN_URL_TEST = 'https://sandbox-oauth.piste.gouv.fr/api/oauth/token';

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
     * Submit a Factur-X invoice to Chorus Pro.
     *
     * @param array{clientId: string, clientSecret: string, siret: string} $credentials
     */
    public function submit(string $xml, string $filename, array $credentials): SubmitResponse
    {
        try {
            $accessToken = $this->authenticate($credentials['clientId'], $credentials['clientSecret']);

            // Chorus Pro expects a multipart upload with invoice metadata + XML file
            $response = $this->httpClient->request('POST', $this->baseUrl . '/deposer/flux', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Accept' => 'application/json',
                    'cpro-account' => $credentials['siret'],
                ],
                'json' => [
                    'fichierFlux' => base64_encode($xml),
                    'nomFichier' => $filename,
                    'syntaxeFlux' => 'IN_DP_E2_CII_FACTURX',
                    'avecSignature' => false,
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $data = json_decode($response->getContent(false), true) ?? [];

            if ($statusCode >= 200 && $statusCode < 300) {
                return new SubmitResponse(
                    success: true,
                    externalId: (string) ($data['numeroFluxDepot'] ?? $data['identifiantFactureCPP'] ?? ''),
                    metadata: $data,
                );
            }

            return new SubmitResponse(
                success: false,
                errorMessage: $data['libelle'] ?? $data['message'] ?? "Chorus Pro returned HTTP {$statusCode}",
                metadata: $data,
            );
        } catch (\Throwable $e) {
            $this->logger->error('ChorusProClient: Submission failed.', [
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
     * Check invoice status on Chorus Pro.
     */
    public function checkStatus(string $externalId, array $credentials): StatusResponse
    {
        try {
            $accessToken = $this->authenticate($credentials['clientId'], $credentials['clientSecret']);

            $response = $this->httpClient->request('POST', $this->baseUrl . '/consulter/flux', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Accept' => 'application/json',
                    'cpro-account' => $credentials['siret'],
                ],
                'json' => [
                    'numeroFluxDepot' => $externalId,
                ],
            ]);

            $data = json_decode($response->getContent(false), true) ?? [];
            $status = $this->mapChorusStatus($data['etatCourantFlux'] ?? $data['codeRetour'] ?? 'unknown');

            return new StatusResponse(
                status: $status,
                errorMessage: $data['libelle'] ?? null,
                metadata: $data,
            );
        } catch (\Throwable $e) {
            $this->logger->error('ChorusProClient: Status check failed.', [
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
                'scope' => 'openid',
            ],
        ]);

        $data = json_decode($response->getContent(false), true);

        if (!isset($data['access_token'])) {
            throw new \RuntimeException(
                'Chorus Pro OAuth2 authentication failed: ' . ($data['error_description'] ?? $data['error'] ?? 'Unknown error')
            );
        }

        return $data['access_token'];
    }

    private function mapChorusStatus(string $chorusStatus): EInvoiceSubmissionStatus
    {
        return match (strtolower($chorusStatus)) {
            'en_cours_traitement', 'en_attente', 'deposee', 'in_progress' => EInvoiceSubmissionStatus::PENDING,
            'traitee', 'mise_a_disposition', 'acceptee', 'processed' => EInvoiceSubmissionStatus::ACCEPTED,
            'rejetee', 'refusee', 'rejected' => EInvoiceSubmissionStatus::REJECTED,
            default => EInvoiceSubmissionStatus::PENDING,
        };
    }
}
