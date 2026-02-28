<?php

namespace App\Controller\Api\V1;

use App\Entity\Company;
use App\Manager\CompanyManager;
use App\Message\DeleteCompanyDataMessage;
use App\Message\ResetCompanyDataMessage;
use App\Repository\CompanyRepository;
use App\Repository\OrganizationMembershipRepository;
use App\Repository\StripeConnectAccountRepository;
use App\Security\OrganizationContext;
use App\Service\Anaf\AnafTokenResolver;
use App\Service\Centrifugo\CentrifugoService;
use App\Service\CompanyReadOnlyService;
use App\Service\LicenseManager;
use App\Service\Storage\OrganizationStorageResolver;
use App\Util\AddressNormalizer;
use App\Service\Webhook\WebhookDispatcher;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Psr\Cache\CacheItemPoolInterface;

#[Route('/api/v1/companies')]
class CompanyController extends AbstractController
{
    public function __construct(
        private readonly CompanyRepository $companyRepository,
        private readonly CompanyManager $companyManager,
        private readonly OrganizationContext $organizationContext,
        private readonly EntityManagerInterface $entityManager,
        private readonly AnafTokenResolver $tokenResolver,
        private readonly LicenseManager $licenseManager,
        private readonly CentrifugoService $centrifugo,
        private readonly OrganizationMembershipRepository $membershipRepository,
        private readonly MessageBusInterface $messageBus,
        private readonly WebhookDispatcher $webhookDispatcher,
        private readonly OrganizationStorageResolver $storageResolver,
        private readonly FilesystemOperator $defaultStorage,
        private readonly CompanyReadOnlyService $companyReadOnlyService,
        private readonly StripeConnectAccountRepository $connectAccountRepository,
        private readonly CacheItemPoolInterface $cache,
    ) {}

    #[Route('', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $org = $this->organizationContext->getOrganization();
        if (!$org) {
            return $this->json(['error' => 'No organization found.'], Response::HTTP_NOT_FOUND);
        }

        $membership = $this->organizationContext->getMembership();
        $companies = $this->companyRepository->findByOrganizationAndMembership($org, $membership);

        return $this->json([
            'data' => array_map(fn(Company $c) => $this->serializeCompany($c), $companies),
        ]);
    }

    #[Route('', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $cif = $data['cif'] ?? null;

        if (!$cif) {
            return $this->json(['error' => 'CIF is required.'], Response::HTTP_BAD_REQUEST);
        }

        $org = $this->organizationContext->getOrganization();
        if (!$org) {
            return $this->json(['error' => 'No organization found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->licenseManager->canAddCompany($org)) {
            return $this->json([
                'error' => 'Limita de companii atinsa. Upgradati planul.',
                'code' => 'PLAN_LIMIT',
            ], Response::HTTP_PAYMENT_REQUIRED);
        }

        $companyData = $this->companyManager->getCompanyData((int) $cif);
        if (!$companyData) {
            return $this->json(['error' => 'Company not found at ANAF.'], Response::HTTP_NOT_FOUND);
        }

        // Check if this org already has this CIF (active, non-deleted)
        $existing = $this->companyRepository->findByOrganizationAndCif($org, (int) $cif);
        if ($existing) {
            return $this->json([
                'error' => 'Aceasta companie este deja adaugata in organizatia dvs.',
                'code' => 'DUPLICATE_CIF',
            ], Response::HTTP_CONFLICT);
        }

        $company = Company::createFromAnaf($companyData);
        $company->setOrganization($org);

        $company = $this->companyManager->create($company);
        $this->broadcastCompanyEvent($company, 'company.created');

        $hasToken = $this->tokenResolver->resolve($company) !== null;

        return $this->json(array_merge($this->serializeCompany($company), [
            'hasValidToken' => $hasToken,
        ]), Response::HTTP_CREATED);
    }

    #[Route('/deleted', methods: ['GET'])]
    public function deleted(): JsonResponse
    {
        $org = $this->organizationContext->getOrganization();
        if (!$org) {
            return $this->json(['error' => 'No organization found.'], Response::HTTP_NOT_FOUND);
        }

        $filters = $this->entityManager->getFilters();
        $filterWasEnabled = $filters->isEnabled('soft_delete');
        if ($filterWasEnabled) {
            $filters->disable('soft_delete');
        }

        try {
            $qb = $this->entityManager->createQueryBuilder();
            $now = new \DateTimeImmutable();
            $graceStart = $now->modify('-5 days');

            $companies = $qb->select('c')
                ->from(Company::class, 'c')
                ->where('c.organization = :org')
                ->andWhere('c.deletedAt IS NOT NULL')
                ->andWhere('c.deletedAt > :graceStart')
                ->setParameter('org', $org)
                ->setParameter('graceStart', $graceStart)
                ->getQuery()
                ->getResult();

            return $this->json([
                'data' => array_map(fn(Company $c) => $this->serializeCompany($c), $companies),
            ]);
        } finally {
            if ($filterWasEnabled) {
                $filters->enable('soft_delete');
            }
        }
    }

    #[Route('/{uuid}', methods: ['GET'])]
    public function show(string $uuid): JsonResponse
    {
        $company = $this->companyRepository->find(Uuid::fromString($uuid));
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->denyAccessUnlessGranted('COMPANY_VIEW', $company);

        $hasToken = $this->tokenResolver->resolve($company) !== null;

        return $this->json(array_merge($this->serializeCompany($company), [
            'hasValidToken' => $hasToken,
        ]));
    }

    #[Route('/{uuid}', methods: ['PATCH'])]
    public function update(string $uuid, Request $request): JsonResponse
    {
        $company = $this->companyRepository->find(Uuid::fromString($uuid));
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->denyAccessUnlessGranted('COMPANY_EDIT', $company);

        $data = json_decode($request->getContent(), true);

        if (isset($data['name'])) $company->setName($data['name']);
        if (isset($data['registrationNumber'])) $company->setRegistrationNumber($data['registrationNumber']);
        if (isset($data['vatCode'])) $company->setVatCode($data['vatCode'] ?: null);
        if (isset($data['vatPayer'])) $company->setVatPayer((bool) $data['vatPayer']);
        if (isset($data['address'])) $company->setAddress($data['address']);
        if (isset($data['city']) || isset($data['state'])) {
            $state = $data['state'] ?? $company->getState();
            $city = $data['city'] ?? $company->getCity();
            $normalized = AddressNormalizer::normalizeBucharest($state, $city);
            $company->setState($normalized['county']);
            $company->setCity($normalized['city']);
        }
        if (isset($data['country'])) $company->setCountry($data['country']);
        if (isset($data['phone'])) $company->setPhone($data['phone']);
        if (isset($data['email'])) $company->setEmail($data['email']);
        if (isset($data['website'])) $company->setWebsite($data['website'] ?: null);
        if (isset($data['capitalSocial'])) $company->setCapitalSocial($data['capitalSocial'] ?: null);
        if (isset($data['vatOnCollection'])) $company->setVatOnCollection((bool) $data['vatOnCollection']);
        if (isset($data['oss'])) $company->setOss((bool) $data['oss']);
        if (isset($data['vatIn'])) $company->setVatIn($data['vatIn'] ?: null);
        if (isset($data['eoriCode'])) $company->setEoriCode($data['eoriCode'] ?: null);
        if (isset($data['representative'])) $company->setRepresentative($data['representative'] ?: null);
        if (isset($data['representativeRole'])) $company->setRepresentativeRole($data['representativeRole'] ?: null);
        if (isset($data['bankName'])) $company->setBankName($data['bankName']);
        if (isset($data['bankAccount'])) $company->setBankAccount($data['bankAccount']);
        if (isset($data['bankBic'])) $company->setBankBic($data['bankBic']);
        if (isset($data['defaultCurrency'])) $company->setDefaultCurrency($data['defaultCurrency']);
        if (isset($data['syncDaysBack'])) $company->setSyncDaysBack((int) $data['syncDaysBack']);
        if (isset($data['archiveEnabled'])) $company->setArchiveEnabled((bool) $data['archiveEnabled']);
        if (array_key_exists('archiveRetentionYears', $data)) {
            $company->setArchiveRetentionYears($data['archiveRetentionYears'] !== null ? (int) $data['archiveRetentionYears'] : null);
        }
        if (array_key_exists('efacturaDelayHours', $data)) {
            $value = $data['efacturaDelayHours'];
            if ($value === null) {
                $company->setEfacturaDelayHours(null);
            } else {
                $allowedDelays = [2, 24, 48, 72, 96];
                $delay = (int) $value;
                if (!in_array($delay, $allowedDelays, true)) {
                    return $this->json(['error' => 'efacturaDelayHours must be null or one of: 2, 24, 48, 72, 96.'], Response::HTTP_BAD_REQUEST);
                }
                $company->setEfacturaDelayHours($delay);
            }
        }

        if (array_key_exists('enabledModules', $data)) {
            $value = $data['enabledModules'];
            if ($value === null) {
                $company->setEnabledModules(null);
            } else {
                if (!is_array($value)) {
                    return $this->json(['error' => 'enabledModules must be null or an array of module keys.'], Response::HTTP_BAD_REQUEST);
                }
                $invalid = array_diff($value, Company::ALL_MODULES);
                if (count($invalid) > 0) {
                    return $this->json(['error' => 'Invalid module keys: ' . implode(', ', $invalid) . '. Allowed: ' . implode(', ', Company::ALL_MODULES)], Response::HTTP_BAD_REQUEST);
                }
                // When all modules are enabled, store null instead of the full array
                $filtered = array_values(array_unique($value));
                sort($filtered);
                $allModules = Company::ALL_MODULES;
                sort($allModules);
                $company->setEnabledModules($filtered === $allModules ? null : $filtered);
            }
        }

        $this->entityManager->flush();
        $this->broadcastCompanyEvent($company, 'company.updated');

        return $this->json($this->serializeCompany($company));
    }

    #[Route('/{uuid}', methods: ['DELETE'])]
    public function delete(string $uuid): JsonResponse
    {
        $company = $this->companyRepository->find(Uuid::fromString($uuid));
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->denyAccessUnlessGranted('COMPANY_DELETE', $company);

        $companyId = (string) $company->getId();

        $this->broadcastCompanyEvent($company, 'company.removed');
        $this->companyManager->delete($company);

        // Dispatch background cascade deletion with 5-day grace period
        $this->messageBus->dispatch(
            new DeleteCompanyDataMessage($companyId),
            [new DelayStamp(5 * 86400 * 1000)]
        );

        $deletedAt = $company->getDeletedAt();
        $hardDeleteAt = $deletedAt ? $deletedAt->modify('+5 days') : null;

        return $this->json([
            'message' => 'Company scheduled for deletion.',
            'deletedAt' => $deletedAt?->format('c'),
            'hardDeleteAt' => $hardDeleteAt?->format('c'),
        ]);
    }

    #[Route('/{uuid}/restore', methods: ['POST'])]
    public function restore(string $uuid): JsonResponse
    {
        $filters = $this->entityManager->getFilters();
        $filterWasEnabled = $filters->isEnabled('soft_delete');
        if ($filterWasEnabled) {
            $filters->disable('soft_delete');
        }

        try {
            $company = $this->companyRepository->find(Uuid::fromString($uuid));
            if (!$company) {
                return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
            }

            $this->denyAccessUnlessGranted('COMPANY_DELETE', $company);

            if (!$company->isDeleted()) {
                return $this->json(['error' => 'Company is not deleted.'], Response::HTTP_CONFLICT);
            }

            // Check grace period: if deletedAt + 5 days < now, it's too late
            $deadline = $company->getDeletedAt()->modify('+5 days');
            if ($deadline < new \DateTimeImmutable()) {
                return $this->json(['error' => 'Grace period expired. Company cannot be restored.'], Response::HTTP_GONE);
            }

            $company->restore();
            $this->entityManager->flush();
            $this->broadcastCompanyEvent($company, 'company.restored');

            return $this->json($this->serializeCompany($company));
        } finally {
            if ($filterWasEnabled) {
                $filters->enable('soft_delete');
            }
        }
    }

    #[Route('/{uuid}/reset', methods: ['POST'])]
    public function reset(string $uuid): JsonResponse
    {
        $company = $this->companyRepository->find(Uuid::fromString($uuid));
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->denyAccessUnlessGranted('COMPANY_DELETE', $company);

        $this->messageBus->dispatch(new ResetCompanyDataMessage((string) $company->getId()));
        $this->broadcastCompanyEvent($company, 'company.reset');

        return $this->json(['message' => 'Company data reset initiated.']);
    }

    #[Route('/{uuid}/refresh-anaf', methods: ['POST'])]
    public function refreshAnaf(string $uuid): JsonResponse
    {
        $company = $this->companyRepository->find(Uuid::fromString($uuid));
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->denyAccessUnlessGranted('COMPANY_EDIT', $company);

        // Enforce 1-hour cooldown via cache (Redis in prod)
        $cacheKey = 'anaf_refresh_' . str_replace('-', '_', $uuid);
        $cacheItem = $this->cache->getItem($cacheKey);
        if ($cacheItem->isHit()) {
            $refreshedAt = (int) $cacheItem->get();
            $minutesLeft = max(1, (int) ceil(($refreshedAt + 3600 - time()) / 60));

            return $this->json([
                'error' => "Datele au fost reimprospatatate recent. Puteti reincerca peste {$minutesLeft} min.",
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $refreshed = $this->companyManager->refreshFromAnaf($company);

        // Normalize Bucharest address after ANAF refresh
        $normalized = AddressNormalizer::normalizeBucharest($refreshed->getState(), $refreshed->getCity());
        $refreshed->setState($normalized['county']);
        $refreshed->setCity($normalized['city']);
        $this->entityManager->flush();

        // Store refresh timestamp in cache for 1 hour
        $cacheItem->set(time());
        $cacheItem->expiresAfter(3600);
        $this->cache->save($cacheItem);

        $this->broadcastCompanyEvent($refreshed, 'company.updated');

        return $this->json($this->serializeCompany($refreshed));
    }

    #[Route('/{uuid}/toggle-sync', methods: ['POST'])]
    public function toggleSync(string $uuid): JsonResponse
    {
        $company = $this->companyRepository->find(Uuid::fromString($uuid));
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->denyAccessUnlessGranted('COMPANY_EDIT', $company);

        // When enabling sync, require a valid ANAF token
        if (!$company->isSyncEnabled()) {
            $token = $this->tokenResolver->resolve($company);
            if (!$token) {
                return $this->json([
                    'error' => 'Nu puteti activa sincronizarea fara un token ANAF valid. Conectati-va mai intai la ANAF.',
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        $company->setSyncEnabled(!$company->isSyncEnabled());
        $this->entityManager->flush();
        $this->broadcastCompanyEvent($company, 'company.updated');

        return $this->json([
            'syncEnabled' => $company->isSyncEnabled(),
            'message' => $company->isSyncEnabled() ? 'Sync enabled' : 'Sync disabled',
        ]);
    }

    #[Route('/{uuid}/logo', methods: ['POST'])]
    public function uploadLogo(string $uuid, Request $request): JsonResponse
    {
        $company = $this->companyRepository->find(Uuid::fromString($uuid));
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->denyAccessUnlessGranted('COMPANY_EDIT', $company);

        $file = $request->files->get('logo');
        if (!$file) {
            return $this->json(['error' => 'No file uploaded.'], Response::HTTP_BAD_REQUEST);
        }

        $allowedMimes = ['image/png', 'image/jpeg', 'image/svg+xml'];
        if (!in_array($file->getMimeType(), $allowedMimes, true)) {
            return $this->json(['error' => 'Invalid file type. Allowed: PNG, JPG, SVG.'], Response::HTTP_BAD_REQUEST);
        }

        if ($file->getSize() > 2 * 1024 * 1024) {
            return $this->json(['error' => 'File too large. Maximum 2MB.'], Response::HTTP_BAD_REQUEST);
        }

        // Delete old logo if exists
        $oldPath = $company->getLogoPath();
        if ($oldPath) {
            try {
                $storage = $this->storageResolver->resolveForCompany($company);
                if ($storage->fileExists($oldPath)) {
                    $storage->delete($oldPath);
                }
            } catch (\Throwable) {}
        }

        $ext = $file->guessExtension() ?: 'png';
        $logoPath = sprintf('%s/logo.%s', $company->getCif(), $ext);

        $storage = $this->storageResolver->resolveForCompany($company);
        $storage->write($logoPath, file_get_contents($file->getPathname()));

        $company->setLogoPath($logoPath);
        $this->entityManager->flush();

        return $this->json(['logoPath' => $logoPath, 'message' => 'Logo uploaded.']);
    }

    #[Route('/{uuid}/logo', methods: ['DELETE'])]
    public function deleteLogo(string $uuid): JsonResponse
    {
        $company = $this->companyRepository->find(Uuid::fromString($uuid));
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->denyAccessUnlessGranted('COMPANY_EDIT', $company);

        $logoPath = $company->getLogoPath();
        if ($logoPath) {
            try {
                $storage = $this->storageResolver->resolveForCompany($company);
                if ($storage->fileExists($logoPath)) {
                    $storage->delete($logoPath);
                }
            } catch (\Throwable) {}

            $company->setLogoPath(null);
            $this->entityManager->flush();
        }

        return $this->json(['message' => 'Logo removed.']);
    }

    #[Route('/{uuid}/logo', methods: ['GET'])]
    public function getLogo(string $uuid): Response
    {
        $company = $this->companyRepository->find(Uuid::fromString($uuid));
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->denyAccessUnlessGranted('COMPANY_VIEW', $company);

        $logoPath = $company->getLogoPath();
        if (!$logoPath) {
            return $this->json(['error' => 'No logo.'], Response::HTTP_NOT_FOUND);
        }

        try {
            $storage = $this->storageResolver->resolveForCompany($company);
            if (!$storage->fileExists($logoPath)) {
                return $this->json(['error' => 'Logo file not found.'], Response::HTTP_NOT_FOUND);
            }

            $content = $storage->read($logoPath);
            $mimeType = $storage->mimeType($logoPath);

            return new Response($content, 200, [
                'Content-Type' => $mimeType,
                'Cache-Control' => 'private, max-age=86400',
            ]);
        } catch (\Throwable) {
            return $this->json(['error' => 'Could not read logo.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/{uuid}/set-active', methods: ['PUT'])]
    public function setActive(string $uuid): JsonResponse
    {
        $company = $this->companyRepository->find(Uuid::fromString($uuid));
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->denyAccessUnlessGranted('COMPANY_EDIT', $company);

        $org = $this->organizationContext->getOrganization();
        if (!$org || $company->getOrganization()?->getId() !== $org->getId()) {
            return $this->json(['error' => 'Company does not belong to this organization.'], Response::HTTP_FORBIDDEN);
        }

        $this->companyReadOnlyService->setActiveCompany($org, $company);

        $membership = $this->organizationContext->getMembership();
        $companies = $this->companyRepository->findByOrganizationAndMembership($org, $membership);

        return $this->json([
            'data' => array_map(fn(Company $c) => $this->serializeCompany($c), $companies),
        ]);
    }

    private function broadcastCompanyEvent(Company $company, string $type): void
    {
        $org = $company->getOrganization();
        if (!$org) {
            return;
        }

        $memberships = $this->membershipRepository->findBy([
            'organization' => $org,
            'isActive' => true,
        ]);

        $channels = array_map(
            fn ($m) => 'notifications:user_' . $m->getUser()->getId(),
            $memberships,
        );

        $this->centrifugo->queueBroadcast($channels, [
            'type' => $type,
            'companyId' => (string) $company->getId(),
            'companyName' => $company->getName(),
        ]);

        $this->webhookDispatcher->dispatchForCompany($company, $type, [
            'companyId' => (string) $company->getId(),
            'companyName' => $company->getName(),
        ]);
    }

    private function serializeCompany(Company $company): array
    {
        $deletedAt = $company->getDeletedAt();
        $hardDeleteAt = $deletedAt ? $deletedAt->modify('+5 days') : null;

        return [
            'id' => (string) $company->getId(),
            'name' => $company->getName(),
            'cif' => $company->getCif(),
            'registrationNumber' => $company->getRegistrationNumber(),
            'vatPayer' => $company->isVatPayer(),
            'vatCode' => $company->getVatCode(),
            'address' => $company->getAddress(),
            'city' => $company->getCity(),
            'state' => $company->getState(),
            'country' => $company->getCountry(),
            'phone' => $company->getPhone(),
            'email' => $company->getEmail(),
            'website' => $company->getWebsite(),
            'capitalSocial' => $company->getCapitalSocial(),
            'vatOnCollection' => $company->isVatOnCollection(),
            'oss' => $company->isOss(),
            'vatIn' => $company->getVatIn(),
            'eoriCode' => $company->getEoriCode(),
            'representative' => $company->getRepresentative(),
            'representativeRole' => $company->getRepresentativeRole(),
            'bankName' => $company->getBankName(),
            'bankAccount' => $company->getBankAccount(),
            'bankBic' => $company->getBankBic(),
            'defaultCurrency' => $company->getDefaultCurrency(),
            'syncEnabled' => $company->isSyncEnabled(),
            'lastSyncedAt' => $company->getLastSyncedAt()?->format('c'),
            'syncDaysBack' => $company->getSyncDaysBack(),
            'archiveEnabled' => $company->isArchiveEnabled(),
            'archiveRetentionYears' => $company->getArchiveRetentionYears(),
            'efacturaDelayHours' => $company->getEfacturaDelayHours(),
            'isReadOnly' => $company->isReadOnly(),
            'enabledModules' => $company->getEnabledModules(),
            'deletedAt' => $deletedAt?->format('c'),
            'hardDeleteAt' => $hardDeleteAt?->format('c'),
            'stripeConnect' => $this->serializeStripeConnect($company),
        ];
    }

    private function serializeStripeConnect(Company $company): ?array
    {
        $connectAccount = $this->connectAccountRepository->findByCompany($company);
        if (!$connectAccount) {
            return null;
        }

        return [
            'connected' => true,
            'chargesEnabled' => $connectAccount->isChargesEnabled(),
            'paymentEnabledByDefault' => $connectAccount->isPaymentEnabledByDefault(),
        ];
    }
}
