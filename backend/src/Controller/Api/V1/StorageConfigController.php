<?php

namespace App\Controller\Api\V1;

use App\Entity\StorageConfig;
use App\Repository\StorageConfigRepository;
use App\Security\OrganizationContext;
use App\Service\Storage\CredentialEncryptor;
use App\Service\Storage\StorageConnectionTester;
use App\Service\Storage\StorageProviderRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1')]
class StorageConfigController extends AbstractController
{
    public function __construct(
        private readonly OrganizationContext $organizationContext,
        private readonly StorageConfigRepository $storageConfigRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly CredentialEncryptor $credentialEncryptor,
        private readonly StorageProviderRegistry $providerRegistry,
        private readonly StorageConnectionTester $connectionTester,
    ) {}

    #[Route('/storage-config', methods: ['GET'])]
    public function show(): JsonResponse
    {
        if (!$this->organizationContext->hasPermission('settings.view')) {
            return $this->json(['error' => 'Access denied.'], Response::HTTP_FORBIDDEN);
        }

        $org = $this->organizationContext->getOrganization();
        if (!$org) {
            return $this->json(['error' => 'Organization not found.'], Response::HTTP_NOT_FOUND);
        }

        $config = $this->storageConfigRepository->findByOrganization($org);
        if (!$config) {
            return $this->json(['data' => null]);
        }

        return $this->json(['data' => $this->serialize($config)]);
    }

    #[Route('/storage-config', methods: ['PUT'])]
    public function upsert(Request $request): JsonResponse
    {
        if (!$this->organizationContext->hasPermission('settings.manage')) {
            return $this->json(['error' => 'Access denied.'], Response::HTTP_FORBIDDEN);
        }

        $org = $this->organizationContext->getOrganization();
        if (!$org) {
            return $this->json(['error' => 'Organization not found.'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);

        $provider = $data['provider'] ?? null;
        if (!$provider || !$this->providerRegistry->isValid($provider)) {
            return $this->json(['error' => 'Invalid or missing provider.'], Response::HTTP_BAD_REQUEST);
        }

        $bucket = $data['bucket'] ?? null;
        if (!$bucket) {
            return $this->json(['error' => 'Field "bucket" is required.'], Response::HTTP_BAD_REQUEST);
        }

        $accessKeyId = $data['accessKeyId'] ?? null;
        $secretAccessKey = $data['secretAccessKey'] ?? null;

        $config = $this->storageConfigRepository->findByOrganization($org);
        $isNew = $config === null;

        if ($isNew) {
            if (!$accessKeyId || !$secretAccessKey) {
                return $this->json(['error' => 'Credentials are required.'], Response::HTTP_BAD_REQUEST);
            }
            $config = new StorageConfig();
            $config->setOrganization($org);
        }

        $config->setProvider($provider);
        $config->setBucket($bucket);
        $config->setRegion($data['region'] ?? null);
        $config->setPrefix($data['prefix'] ?? 'documents');
        $config->setForcePathStyle($data['forcePathStyle'] ?? $this->providerRegistry->getProvider($provider)['defaultForcePathStyle'] ?? false);

        // Resolve endpoint
        $endpoint = $data['endpoint'] ?? null;
        if (!$endpoint) {
            $endpointParams = $data;
            $endpointParams['region'] = $data['region'] ?? null;
            $endpoint = $this->providerRegistry->resolveEndpoint($provider, $endpointParams);
        }
        $config->setEndpoint($endpoint);

        if (isset($data['isActive'])) {
            $config->setIsActive((bool) $data['isActive']);
        }

        // Update credentials if provided (both must be given together)
        if ($accessKeyId || $secretAccessKey) {
            if (!$accessKeyId || !$secretAccessKey) {
                return $this->json(['error' => 'Both accessKeyId and secretAccessKey must be provided together.'], Response::HTTP_BAD_REQUEST);
            }
            $credentials = ['accessKeyId' => $accessKeyId, 'secretAccessKey' => $secretAccessKey];
            if (isset($data['accountId'])) {
                $credentials['accountId'] = $data['accountId'];
            }
            $config->setEncryptedCredentials($this->credentialEncryptor->encrypt($credentials));
        }

        if ($isNew) {
            $this->entityManager->persist($config);
        }
        $this->entityManager->flush();

        return $this->json(
            ['data' => $this->serialize($config)],
            $isNew ? Response::HTTP_CREATED : Response::HTTP_OK,
        );
    }

    #[Route('/storage-config', methods: ['DELETE'])]
    public function delete(): JsonResponse
    {
        if (!$this->organizationContext->hasPermission('settings.manage')) {
            return $this->json(['error' => 'Access denied.'], Response::HTTP_FORBIDDEN);
        }

        $org = $this->organizationContext->getOrganization();
        if (!$org) {
            return $this->json(['error' => 'Organization not found.'], Response::HTTP_NOT_FOUND);
        }

        $config = $this->storageConfigRepository->findByOrganization($org);
        if (!$config) {
            return $this->json(['error' => 'No storage configuration found.'], Response::HTTP_NOT_FOUND);
        }

        $this->entityManager->remove($config);
        $this->entityManager->flush();

        return $this->json(['message' => 'Storage configuration deleted.']);
    }

    #[Route('/storage-config/test', methods: ['POST'])]
    public function test(Request $request): JsonResponse
    {
        if (!$this->organizationContext->hasPermission('settings.manage')) {
            return $this->json(['error' => 'Access denied.'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);

        $provider = $data['provider'] ?? null;
        if (!$provider || !$this->providerRegistry->isValid($provider)) {
            return $this->json(['error' => 'Invalid or missing provider.'], Response::HTTP_BAD_REQUEST);
        }

        $bucket = $data['bucket'] ?? null;
        if (!$bucket) {
            return $this->json(['error' => 'Field "bucket" is required.'], Response::HTTP_BAD_REQUEST);
        }

        $accessKeyId = $data['accessKeyId'] ?? null;
        $secretAccessKey = $data['secretAccessKey'] ?? null;

        // If credentials not provided, try to use existing config
        if (!$accessKeyId || !$secretAccessKey) {
            $org = $this->organizationContext->getOrganization();
            $existingConfig = $org ? $this->storageConfigRepository->findByOrganization($org) : null;

            if ($existingConfig) {
                $existingCreds = $this->credentialEncryptor->decrypt($existingConfig->getEncryptedCredentials());
                $accessKeyId = $accessKeyId ?: $existingCreds['accessKeyId'];
                $secretAccessKey = $secretAccessKey ?: $existingCreds['secretAccessKey'];
            } else {
                return $this->json(['error' => 'Credentials are required.'], Response::HTTP_BAD_REQUEST);
            }
        }

        $credentials = ['accessKeyId' => $accessKeyId, 'secretAccessKey' => $secretAccessKey];
        if (isset($data['accountId'])) {
            $credentials['accountId'] = $data['accountId'];
        }

        $endpoint = $data['endpoint'] ?? null;
        if (!$endpoint) {
            $endpointParams = $data;
            $endpointParams['region'] = $data['region'] ?? null;
            $endpoint = $this->providerRegistry->resolveEndpoint($provider, $endpointParams);
        }

        $providerMeta = $this->providerRegistry->getProvider($provider);
        $forcePathStyle = $data['forcePathStyle'] ?? $providerMeta['defaultForcePathStyle'] ?? false;
        $region = $data['region'] ?? null;

        $result = $this->connectionTester->test($provider, $credentials, $bucket, $region, $endpoint, $forcePathStyle);

        // Update last_tested_at on existing config if test is for current config
        if ($result['success']) {
            $org = $this->organizationContext->getOrganization();
            $config = $org ? $this->storageConfigRepository->findByOrganization($org) : null;
            if ($config) {
                $config->setLastTestedAt(new \DateTimeImmutable());
                $this->entityManager->flush();
            }
        }

        return $this->json($result);
    }

    #[Route('/storage-config/providers', methods: ['GET'])]
    public function providers(): JsonResponse
    {
        if (!$this->organizationContext->hasPermission('settings.view')) {
            return $this->json(['error' => 'Access denied.'], Response::HTTP_FORBIDDEN);
        }

        return $this->json(['data' => $this->providerRegistry->getProviders()]);
    }

    private function serialize(StorageConfig $config): array
    {
        return [
            'id' => (string) $config->getId(),
            'provider' => $config->getProvider(),
            'bucket' => $config->getBucket(),
            'region' => $config->getRegion(),
            'endpoint' => $config->getEndpoint(),
            'prefix' => $config->getPrefix(),
            'forcePathStyle' => $config->isForcePathStyle(),
            'isActive' => $config->isActive(),
            'lastTestedAt' => $config->getLastTestedAt()?->format('c'),
            'createdAt' => $config->getCreatedAt()?->format('c'),
            'updatedAt' => $config->getUpdatedAt()?->format('c'),
        ];
    }
}
