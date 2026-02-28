<?php

namespace App\Service\Storage;

class StorageProviderRegistry
{
    private const PROVIDERS = [
        'aws_s3' => [
            'name' => 'Amazon S3',
            'fields' => [
                ['key' => 'accessKeyId', 'label' => 'Access Key ID', 'type' => 'text', 'required' => true],
                ['key' => 'secretAccessKey', 'label' => 'Secret Access Key', 'type' => 'password', 'required' => true],
                ['key' => 'bucket', 'label' => 'Bucket', 'type' => 'text', 'required' => true],
                ['key' => 'region', 'label' => 'Region', 'type' => 'text', 'required' => true],
            ],
            'supportsRegion' => true,
            'supportsEndpoint' => false,
            'defaultForcePathStyle' => false,
        ],
        'digitalocean_spaces' => [
            'name' => 'DigitalOcean Spaces',
            'fields' => [
                ['key' => 'accessKeyId', 'label' => 'Spaces Key', 'type' => 'text', 'required' => true],
                ['key' => 'secretAccessKey', 'label' => 'Spaces Secret', 'type' => 'password', 'required' => true],
                ['key' => 'bucket', 'label' => 'Space Name', 'type' => 'text', 'required' => true],
                ['key' => 'region', 'label' => 'Region', 'type' => 'text', 'required' => true, 'placeholder' => 'nyc3, ams3, sgp1...'],
            ],
            'supportsRegion' => true,
            'supportsEndpoint' => false,
            'defaultForcePathStyle' => false,
        ],
        'backblaze_b2' => [
            'name' => 'Backblaze B2',
            'fields' => [
                ['key' => 'accessKeyId', 'label' => 'Application Key ID', 'type' => 'text', 'required' => true],
                ['key' => 'secretAccessKey', 'label' => 'Application Key', 'type' => 'password', 'required' => true],
                ['key' => 'bucket', 'label' => 'Bucket Name', 'type' => 'text', 'required' => true],
                ['key' => 'region', 'label' => 'Region', 'type' => 'text', 'required' => true, 'placeholder' => 'us-west-004, eu-central-003...'],
            ],
            'supportsRegion' => true,
            'supportsEndpoint' => false,
            'defaultForcePathStyle' => false,
        ],
        'cloudflare_r2' => [
            'name' => 'Cloudflare R2',
            'fields' => [
                ['key' => 'accessKeyId', 'label' => 'Access Key ID', 'type' => 'text', 'required' => true],
                ['key' => 'secretAccessKey', 'label' => 'Secret Access Key', 'type' => 'password', 'required' => true],
                ['key' => 'bucket', 'label' => 'Bucket Name', 'type' => 'text', 'required' => true],
                ['key' => 'accountId', 'label' => 'Account ID', 'type' => 'text', 'required' => true],
            ],
            'supportsRegion' => false,
            'supportsEndpoint' => false,
            'defaultForcePathStyle' => false,
        ],
        'minio' => [
            'name' => 'MinIO / S3 Compatible',
            'fields' => [
                ['key' => 'accessKeyId', 'label' => 'Access Key', 'type' => 'text', 'required' => true],
                ['key' => 'secretAccessKey', 'label' => 'Secret Key', 'type' => 'password', 'required' => true],
                ['key' => 'bucket', 'label' => 'Bucket', 'type' => 'text', 'required' => true],
                ['key' => 'endpoint', 'label' => 'Endpoint URL', 'type' => 'text', 'required' => true, 'placeholder' => 'https://minio.example.com'],
                ['key' => 'region', 'label' => 'Region', 'type' => 'text', 'required' => false, 'placeholder' => 'us-east-1 (optional)'],
            ],
            'supportsRegion' => true,
            'supportsEndpoint' => true,
            'defaultForcePathStyle' => true,
        ],
    ];

    public function getProviders(): array
    {
        $result = [];
        foreach (self::PROVIDERS as $value => $meta) {
            $result[] = [
                'value' => $value,
                'name' => $meta['name'],
                'fields' => $meta['fields'],
                'supportsRegion' => $meta['supportsRegion'],
                'supportsEndpoint' => $meta['supportsEndpoint'],
                'defaultForcePathStyle' => $meta['defaultForcePathStyle'],
            ];
        }

        return $result;
    }

    public function getProvider(string $key): ?array
    {
        return self::PROVIDERS[$key] ?? null;
    }

    public function isValid(string $key): bool
    {
        return isset(self::PROVIDERS[$key]);
    }

    public function resolveEndpoint(string $provider, array $params): ?string
    {
        return match ($provider) {
            'digitalocean_spaces' => sprintf('https://%s.digitaloceanspaces.com', $params['region'] ?? 'nyc3'),
            'backblaze_b2' => sprintf('https://s3.%s.backblazeb2.com', $params['region'] ?? 'us-west-004'),
            'cloudflare_r2' => sprintf('https://%s.r2.cloudflarestorage.com', $params['accountId'] ?? ''),
            'minio' => $params['endpoint'] ?? null,
            default => null,
        };
    }

    public function resolveRegion(string $provider, ?string $region): string
    {
        return match ($provider) {
            'cloudflare_r2' => 'auto',
            default => $region ?? 'us-east-1',
        };
    }
}
