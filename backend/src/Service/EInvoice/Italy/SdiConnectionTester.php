<?php

namespace App\Service\EInvoice\Italy;

use App\Service\EInvoice\EInvoiceConnectionTesterInterface;
use App\Service\Security\OutboundUrlPolicy;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AutoconfigureTag('app.einvoice_connection_tester', ['provider' => 'sdi'])]
class SdiConnectionTester implements EInvoiceConnectionTesterInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly OutboundUrlPolicy $outboundUrlPolicy,
        private readonly LoggerInterface $logger,
    ) {}

    public function test(array $config): array
    {
        $hasDirect = !empty($config['certPassword']);
        $hasIntermediary = !empty($config['apiEndpoint']) && !empty($config['apiKey']);

        if (!$hasDirect && !$hasIntermediary) {
            return ['success' => false, 'error' => 'Provide either certificate password (direct) or API endpoint + key (intermediary).'];
        }

        if ($hasIntermediary) {
            try {
                $apiEndpoint = $this->outboundUrlPolicy->assertAllowed((string) $config['apiEndpoint'], ['httpsOnly' => true]);
            } catch (\InvalidArgumentException) {
                return ['success' => false, 'error' => 'API endpoint URL is not allowed.'];
            }

            try {
                $response = $this->httpClient->request('GET', rtrim($apiEndpoint, '/') . '/status', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $config['apiKey'],
                        'Accept' => 'application/json',
                    ],
                    'timeout' => 10,
                    'max_duration' => 15,
                    'max_redirects' => 0,
                ]);

                $statusCode = $response->getStatusCode();
                if ($statusCode >= 200 && $statusCode < 400) {
                    return ['success' => true, 'error' => null];
                }

                return ['success' => false, 'error' => 'Intermediary returned HTTP ' . $statusCode];
            } catch (\Throwable $e) {
                $this->logger->warning('SDI intermediary connection test failed.', [
                    'endpoint' => $apiEndpoint,
                    'error' => $e->getMessage(),
                ]);

                return ['success' => false, 'error' => 'Connection test failed.'];
            }
        }

        // Direct cert mode — we can only validate that the password is non-empty
        // (actual cert validation requires the cert file on disk)
        return ['success' => true, 'error' => null];
    }
}
