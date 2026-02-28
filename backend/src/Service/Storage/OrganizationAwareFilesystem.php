<?php

namespace App\Service\Storage;

use App\Entity\StorageConfig;
use App\Repository\StorageConfigRepository;
use App\Security\OrganizationContext;
use Aws\S3\S3Client;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;

class OrganizationAwareFilesystem implements FilesystemOperator
{
    /** @var array<string, FilesystemOperator> */
    private array $cache = [];

    public function __construct(
        private readonly FilesystemOperator $platformStorage,
        private readonly OrganizationContext $organizationContext,
        private readonly StorageConfigRepository $storageConfigRepository,
        private readonly CredentialEncryptor $credentialEncryptor,
        private readonly StorageProviderRegistry $providerRegistry,
    ) {}

    public function fileExists(string $location): bool
    {
        return $this->resolve()->fileExists($location);
    }

    public function directoryExists(string $location): bool
    {
        return $this->resolve()->directoryExists($location);
    }

    public function has(string $location): bool
    {
        return $this->resolve()->has($location);
    }

    public function read(string $location): string
    {
        return $this->resolve()->read($location);
    }

    public function readStream(string $location)
    {
        return $this->resolve()->readStream($location);
    }

    public function listContents(string $location, bool $deep = self::LIST_SHALLOW): \League\Flysystem\DirectoryListing
    {
        return $this->resolve()->listContents($location, $deep);
    }

    public function lastModified(string $path): int
    {
        return $this->resolve()->lastModified($path);
    }

    public function fileSize(string $path): int
    {
        return $this->resolve()->fileSize($path);
    }

    public function mimeType(string $path): string
    {
        return $this->resolve()->mimeType($path);
    }

    public function visibility(string $path): string
    {
        return $this->resolve()->visibility($path);
    }

    public function write(string $location, string $contents, array $config = []): void
    {
        $this->resolve()->write($location, $contents, $config);
    }

    public function writeStream(string $location, $contents, array $config = []): void
    {
        $this->resolve()->writeStream($location, $contents, $config);
    }

    public function setVisibility(string $path, string $visibility): void
    {
        $this->resolve()->setVisibility($path, $visibility);
    }

    public function delete(string $location): void
    {
        $this->resolve()->delete($location);
    }

    public function deleteDirectory(string $location): void
    {
        $this->resolve()->deleteDirectory($location);
    }

    public function createDirectory(string $location, array $config = []): void
    {
        $this->resolve()->createDirectory($location, $config);
    }

    public function move(string $source, string $destination, array $config = []): void
    {
        $this->resolve()->move($source, $destination, $config);
    }

    public function copy(string $source, string $destination, array $config = []): void
    {
        $this->resolve()->copy($source, $destination, $config);
    }

    public function publicUrl(string $path, array $config = []): string
    {
        return $this->resolve()->publicUrl($path, $config);
    }

    public function temporaryUrl(string $path, \DateTimeInterface $expiresAt, array $config = []): string
    {
        return $this->resolve()->temporaryUrl($path, $expiresAt, $config);
    }

    public function checksum(string $path, array $config = []): string
    {
        return $this->resolve()->checksum($path, $config);
    }

    private function resolve(): FilesystemOperator
    {
        $org = $this->organizationContext->getOrganization();
        if (!$org) {
            return $this->platformStorage;
        }

        $orgId = (string) $org->getId();

        if (isset($this->cache[$orgId])) {
            return $this->cache[$orgId];
        }

        $config = $this->storageConfigRepository->findByOrganization($org);
        if (!$config || !$config->isActive()) {
            $this->cache[$orgId] = $this->platformStorage;

            return $this->platformStorage;
        }

        $filesystem = $this->buildFilesystem($config);
        $this->cache[$orgId] = $filesystem;

        return $filesystem;
    }

    private function buildFilesystem(StorageConfig $config): FilesystemOperator
    {
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

        return new Filesystem($adapter);
    }
}
