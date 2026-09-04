<?php

namespace App\Service\Storage;

use Aws\S3\S3Client;
use Psr\Log\LoggerInterface;

class StorageConnectionTester
{
    public function __construct(
        private readonly StorageProviderRegistry $providerRegistry,
        private readonly LoggerInterface $logger,
    ) {}

    public function test(string $provider, array $credentials, string $bucket, ?string $region, ?string $endpoint, bool $forcePathStyle): array
    {
        try {
            $resolvedEndpoint = $endpoint ?: $this->providerRegistry->resolveEndpoint($provider, $credentials + ['region' => $region]);
            $resolvedRegion = $this->providerRegistry->resolveRegion($provider, $region);

            $config = [
                'version' => 'latest',
                'region' => $resolvedRegion,
                'credentials' => [
                    'key' => $credentials['accessKeyId'],
                    'secret' => $credentials['secretAccessKey'],
                ],
                // Guzzle request options — keep the probe short and never follow
                // redirects (a redirect could point the SDK at an internal host).
                'http' => [
                    'connect_timeout' => 5,
                    'timeout' => 10,
                    'allow_redirects' => false,
                ],
                'retries' => 0,
            ];

            if ($resolvedEndpoint) {
                $config['endpoint'] = $resolvedEndpoint;
            }

            if ($forcePathStyle) {
                $config['use_path_style_endpoint'] = true;
            }

            $client = new S3Client($config);

            $testKey = sprintf('_storno_test_%d.txt', time());
            $testContent = 'Storno connection test — ' . date('c');

            $client->putObject([
                'Bucket' => $bucket,
                'Key' => $testKey,
                'Body' => $testContent,
            ]);

            $result = $client->getObject([
                'Bucket' => $bucket,
                'Key' => $testKey,
            ]);

            $readBack = (string) $result['Body'];

            $client->deleteObject([
                'Bucket' => $bucket,
                'Key' => $testKey,
            ]);

            if ($readBack !== $testContent) {
                return ['success' => false, 'error' => 'Read-back verification failed'];
            }

            return ['success' => true];
        } catch (\Throwable $e) {
            $this->logger->warning('Storage connection test failed.', [
                'provider' => $provider,
                'bucket' => $bucket,
                'endpoint' => $resolvedEndpoint ?? $endpoint,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => 'Connection test failed.'];
        }
    }
}
