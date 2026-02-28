<?php

namespace App\Service\Anaf;

use App\DTO\Anaf\EFacturaStatusResponse;
use App\DTO\Anaf\EFacturaUploadResponse;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class EFacturaClient
{
    private const BASE_URL_PROD = 'https://api.anaf.ro/prod/FCTEL/rest';
    private const BASE_URL_TEST = 'https://api.anaf.ro/test/FCTEL/rest';

    private readonly string $baseUrl;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly AnafRateLimiter $rateLimiter,
        #[Autowire('%kernel.environment%')]
        string $environment = 'prod',
    ) {
        $this->baseUrl = $environment === 'prod' ? self::BASE_URL_PROD : self::BASE_URL_TEST;
        // $this->baseUrl = self::BASE_URL_PROD;
    }

    /**
     * Upload a UBL XML invoice to ANAF e-Factura.
     */
    public function upload(string $xml, string $cif, string $token): EFacturaUploadResponse
    {
        $this->rateLimiter->consumeGlobal();

        $response = $this->httpClient->request('POST', $this->baseUrl . '/upload', [
            'headers' => $this->buildHeaders($token),
            'query' => [
                'standard' => 'UBL',
                'cif' => $cif,
            ],
            'body' => $xml,
        ]);

        $content = $response->getContent(false);
        $data = $this->parseXmlResponse($content);

        return EFacturaUploadResponse::fromResponse($data);
    }

    /**
     * Check the processing status of a previously uploaded invoice.
     */
    public function checkStatus(string $uploadId, string $token): EFacturaStatusResponse
    {
        $this->rateLimiter->consumeGlobal();
        $this->rateLimiter->consumeStare($uploadId);

        $response = $this->httpClient->request('GET', $this->baseUrl . '/stareMesaj', [
            'headers' => $this->buildHeaders($token),
            'query' => [
                'id_incarcare' => $uploadId,
            ],
        ]);

        $content = $response->getContent(false);
        $data = $this->parseXmlResponse($content);

        return EFacturaStatusResponse::fromResponse($data);
    }

    /**
     * Download a processed document by its download ID.
     */
    public function download(string $id, string $token): string
    {
        $this->rateLimiter->consumeGlobal();
        $this->rateLimiter->consumeDescarcare($id);

        $response = $this->httpClient->request('GET', $this->baseUrl . '/descarcare', [
            'headers' => $this->buildHeaders($token),
            'query' => [
                'id' => $id,
            ],
        ]);

        return $response->getContent(false);
    }

    /**
     * List recent invoice messages for a given CIF.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listMessages(string $cif, string $token, int $days = 60): array
    {
        $this->rateLimiter->consumeGlobal();
        $this->rateLimiter->consumeLista($cif);

        $response = $this->httpClient->request('GET', $this->baseUrl . '/listaMesajeFactura', [
            'headers' => $this->buildHeaders($token),
            'query' => [
                'zile' => $days,
                'cif' => $cif,
            ],
        ]);

        $content = $response->getContent(false);

        return json_decode($content, true) ?? [];
    }

    /**
     * Validate a token by calling listaMesajeFactura and checking both HTTP status and response body.
     *
     * @return array{valid: bool, error: ?string, statusCode: int}
     */
    public function validateToken(string $cif, string $token): array
    {
        $this->rateLimiter->consumeGlobal();
        $this->rateLimiter->consumeLista($cif);

        $response = $this->httpClient->request('GET', $this->baseUrl . '/listaMesajeFactura', [
            'headers' => $this->buildHeaders($token),
            'query' => [
                'zile' => 1,
                'cif' => $cif,
            ],
        ]);

        $statusCode = $response->getStatusCode();
        $content = $response->getContent(false);

        // HTTP-level errors (401 unauthorized, 403 forbidden, etc.)
        if ($statusCode >= 400) {
            return [
                'valid' => false,
                'error' => sprintf('ANAF a returnat HTTP %d. Token-ul nu este valid sau nu are acces la CIF-ul %s.', $statusCode, $cif),
                'statusCode' => $statusCode,
            ];
        }

        // Parse response body
        $data = json_decode($content, true);
        // Not valid JSON
        if ($data === null) {
            return [
                'valid' => false,
                'error' => 'Raspuns invalid de la ANAF. Token-ul nu pare sa fie valid.',
                'statusCode' => $statusCode,
            ];
        }

        // ANAF returns {"eroare": "..."} when there's an error
        // Use !empty() instead of isset() because ANAF may return "eroare": null for success
        // However, "nu exista mesaje/niciun mesaj" is NOT an error — it means the token is valid but no invoices exist
        if (!empty($data['eroare'])) {
            $errorMsg = mb_strtolower($data['eroare']);
            $isNoMessages = str_contains($errorMsg, 'nu exista') && str_contains($errorMsg, 'mesaj');

            if (!$isNoMessages) {
                return [
                    'valid' => false,
                    'error' => $data['eroare'],
                    'statusCode' => $statusCode,
                ];
            }
        }

        // Success — ANAF returned a valid response (may have mesaje or be empty)
        return [
            'valid' => true,
            'error' => null,
            'statusCode' => $statusCode,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function buildHeaders(string $token): array
    {
        return [
            'Authorization' => sprintf('Bearer %s', $token),
            'Content-Type' => 'text/plain',
        ];
    }

    /**
     * Parse the XML response returned by ANAF into an associative array.
     *
     * @return array<string, mixed>
     */
    private function parseXmlResponse(string $content): array
    {
        $xml = @simplexml_load_string($content);

        if ($xml === false) {
            return ['eroare' => 'Failed to parse ANAF response'];
        }

        $json = json_encode($xml);
        $decoded = json_decode($json, true);

        // ANAF wraps the result in a nested element; extract the first child.
        if (is_array($decoded)) {
            foreach ($decoded as $value) {
                if (is_array($value)) {
                    return $value;
                }
            }
        }

        return $decoded ?? [];
    }
}
