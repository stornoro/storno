<?php

namespace App\Service\Storage;

use App\Entity\Company;
use App\Repository\StorageConfigRepository;
use Aws\S3\S3Client;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;

class OrganizationStorageResolver
{
    /** @var array<string, FilesystemOperator> */
    private array $cache = [];

    public function __construct(
        private readonly FilesystemOperator $defaultStorage,
        private readonly StorageConfigRepository $storageConfigRepository,
        private readonly CredentialEncryptor $credentialEncryptor,
        private readonly StorageProviderRegistry $providerRegistry,
    ) {}

    public function resolveForCompany(Company $company): FilesystemOperator
    {
        $org = $company->getOrganization();
        if (!$org) {
            return $this->defaultStorage;
        }

        $orgId = (string) $org->getId();

        if (isset($this->cache[$orgId])) {
            return $this->cache[$orgId];
        }

        $config = $this->storageConfigRepository->findByOrganization($org);
        if (!$config || !$config->isActive()) {
            $this->cache[$orgId] = $this->defaultStorage;

            return $this->defaultStorage;
        }

        $credentials = $this->credentialEncryptor->decrypt($config->getEncryptedCredentials());
        $provider = $config->getProvider();

        $resolvedEndpoint = $config->getEndpoint() ?: $this->providerRegistry->resolveEndpoint($provider, $credentials + ['region' => $config->getRegion()]);
        $resolvedRegion = $this->providerRegistry->resolveRegion($provider, $config->getRegion());

        $s3Config = [
            'version' => 'latest',
            'region' => $resolvedRegion,
            'credentials' => [
                'key' => $credentials['accessKeyId'],
                'secret' => $credentials['secretAccessKey'],
            ],
        ];

        if ($resolvedEndpoint) {
            $s3Config['endpoint'] = $resolvedEndpoint;
        }

        if ($config->isForcePathStyle()) {
            $s3Config['use_path_style_endpoint'] = true;
        }

        $client = new S3Client($s3Config);
        $adapter = new AwsS3V3Adapter($client, $config->getBucket(), $config->getPrefix() ?? '');
        $filesystem = new Filesystem($adapter);

        $this->cache[$orgId] = $filesystem;

        return $filesystem;
    }
}
