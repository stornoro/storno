<?php

namespace App\Service\Anaf;

use App\DTO\Anaf\ETransportUploadResponse;
use App\DTO\Anaf\ETransportStatusResponse;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ETransportClient
{
    private const BASE_URL_PROD = 'https://api.anaf.ro/prod/ETRANSPORT/ws/v1';
    private const BASE_URL_TEST = 'https://api.anaf.ro/test/ETRANSPORT/ws/v1';

    private readonly string $baseUrl;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly AnafRateLimiter $rateLimiter,
        #[Autowire('%kernel.environment%')]
        string $environment = 'prod',
    ) {
        $this->baseUrl = $environment === 'prod' ? self::BASE_URL_PROD : self::BASE_URL_TEST;
    }

    /**
     * Upload an e-Transport XML declaration to ANAF.
     */
    public function upload(string $xml, string $cif, string $token): ETransportUploadResponse
    {
        $this->rateLimiter->consumeGlobal();

        $url = sprintf('%s/upload/ETRANSP/%s/2', $this->baseUrl, $cif);

        $response = $this->httpClient->request('POST', $url, [
            'headers' => [
                'Authorization' => sprintf('Bearer %s', $token),
                'Content-Type' => 'application/xml',
            ],
            'body' => $xml,
        ]);

        $content = $response->getContent(false);
        $data = json_decode($content, true) ?? [];

        return ETransportUploadResponse::fromResponse($data);
    }

    /**
     * Check the processing status of a previously uploaded declaration.
     */
    public function checkStatus(string $uploadId, string $token): ETransportStatusResponse
    {
        $this->rateLimiter->consumeGlobal();
        $this->rateLimiter->consumeStare($uploadId);

        $url = sprintf('%s/stareMesaj/%s', $this->baseUrl, $uploadId);

        $response = $this->httpClient->request('GET', $url, [
            'headers' => [
                'Authorization' => sprintf('Bearer %s', $token),
            ],
        ]);

        $content = $response->getContent(false);
        $data = json_decode($content, true) ?? [];

        return ETransportStatusResponse::fromResponse($data);
    }

    /**
     * List recent e-Transport declarations for a given CIF.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listDeclarations(string $cif, string $token, int $days = 60): array
    {
        $this->rateLimiter->consumeGlobal();
        $this->rateLimiter->consumeLista($cif);

        $url = sprintf('%s/lista/%d/%s', $this->baseUrl, $days, $cif);

        $response = $this->httpClient->request('GET', $url, [
            'headers' => [
                'Authorization' => sprintf('Bearer %s', $token),
            ],
        ]);

        $content = $response->getContent(false);

        return json_decode($content, true) ?? [];
    }
}
