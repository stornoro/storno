<?php

namespace App\Controller\Api\V1;

use App\Entity\WhiteLabelConfig;
use App\Repository\WhiteLabelConfigRepository;
use App\Security\OrganizationContext;
use App\Service\LicenseManager;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1')]
class WhiteLabelConfigController extends AbstractController
{
    public function __construct(
        private readonly WhiteLabelConfigRepository $configRepository,
        private readonly OrganizationContext $organizationContext,
        private readonly LicenseManager $licenseManager,
        private readonly EntityManagerInterface $entityManager,
        private readonly FilesystemOperator $platformStorage,
    ) {}

    #[Route('/white-label-config', methods: ['GET'])]
    public function show(): JsonResponse
    {
        if (!$this->organizationContext->hasPermission('settings.view')) {
            return $this->json(['error' => 'Access denied.'], Response::HTTP_FORBIDDEN);
        }

        $org = $this->organizationContext->getOrganization();
        if (!$org) {
            return $this->json(['error' => 'Organization not found.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'entitled' => $this->licenseManager->canUseWhiteLabel($org),
            'data' => $this->serialize($this->configRepository->findByOrganization($org)),
        ]);
    }

    #[Route('/white-label-config', methods: ['PUT'])]
    public function upsert(Request $request): JsonResponse
    {
        if (!$this->organizationContext->hasPermission('settings.manage')) {
            return $this->json(['error' => 'Access denied.'], Response::HTTP_FORBIDDEN);
        }

        $org = $this->organizationContext->getOrganization();
        if (!$org) {
            return $this->json(['error' => 'Organization not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->licenseManager->canUseWhiteLabel($org)) {
            return $this->json(['error' => 'White-label is available on the Business plan.'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true) ?? [];

        $config = $this->configRepository->findByOrganization($org);
        if (!$config) {
            $config = new WhiteLabelConfig();
            $config->setOrganization($org);
            $this->entityManager->persist($config);
        }

        if (isset($data['enabled'])) {
            $config->setEnabled((bool) $data['enabled']);
        }

        if (array_key_exists('appName', $data)) {
            $name = $data['appName'];
            $config->setAppName(($name !== null && $name !== '') ? mb_substr((string) $name, 0, 100) : null);
        }

        if (array_key_exists('primaryColor', $data)) {
            if ($data['primaryColor'] !== null && !preg_match('/^#[0-9a-fA-F]{6}$/', $data['primaryColor'])) {
                return $this->json(['error' => 'Invalid color format. Use hex (e.g. #2563eb).'], Response::HTTP_BAD_REQUEST);
            }
            $config->setPrimaryColor($data['primaryColor']);
        }

        if (isset($data['removeBranding'])) {
            $config->setRemoveBranding((bool) $data['removeBranding']);
        }

        if (array_key_exists('customDomain', $data)) {
            $raw = $data['customDomain'];
            if ($raw === null || $raw === '') {
                $config->setCustomDomain(null);
                $config->setCustomDomainToken(null);
                $config->setCustomDomainVerifiedAt(null);
            } else {
                $domain = $this->normalizeDomain((string) $raw);
                if ($domain === null) {
                    return $this->json(['error' => 'Invalid domain. Use a bare hostname, e.g. facturi.example.com.'], Response::HTTP_BAD_REQUEST);
                }
                if (str_contains($domain, 'storno.ro')) {
                    return $this->json(['error' => 'This domain is not allowed.'], Response::HTTP_BAD_REQUEST);
                }
                // Reset verification whenever the domain changes
                if ($config->getCustomDomain() !== $domain) {
                    $config->setCustomDomain($domain);
                    $config->setCustomDomainToken(bin2hex(random_bytes(16)));
                    $config->setCustomDomainVerifiedAt(null);
                }
            }
        }

        $this->entityManager->flush();

        return $this->json(['data' => $this->serialize($config)]);
    }

    #[Route('/white-label-config/domain/verify', methods: ['POST'])]
    public function verifyDomain(): JsonResponse
    {
        if (!$this->organizationContext->hasPermission('settings.manage')) {
            return $this->json(['error' => 'Access denied.'], Response::HTTP_FORBIDDEN);
        }

        $org = $this->organizationContext->getOrganization();
        if (!$org) {
            return $this->json(['error' => 'Organization not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->licenseManager->canUseWhiteLabel($org)) {
            return $this->json(['error' => 'White-label is available on the Business plan.'], Response::HTTP_FORBIDDEN);
        }

        $config = $this->configRepository->findByOrganization($org);
        if (!$config || !$config->getCustomDomain() || !$config->getCustomDomainToken()) {
            return $this->json(['success' => false, 'error' => 'No domain to verify.'], Response::HTTP_BAD_REQUEST);
        }

        $recordName = '_storno-verify.' . $config->getCustomDomain();
        $expected = $config->getCustomDomainToken();

        $found = false;
        $records = @dns_get_record($recordName, DNS_TXT) ?: [];
        foreach ($records as $record) {
            if (isset($record['txt']) && trim($record['txt']) === $expected) {
                $found = true;
                break;
            }
        }

        if (!$found) {
            return $this->json([
                'success' => false,
                'error' => 'TXT record not found yet. DNS changes can take a few minutes to propagate.',
                'expected' => ['name' => $recordName, 'type' => 'TXT', 'value' => $expected],
            ]);
        }

        $config->setCustomDomainVerifiedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        return $this->json(['success' => true, 'data' => $this->serialize($config)]);
    }

    private function normalizeDomain(string $raw): ?string
    {
        $domain = strtolower(trim($raw));
        $domain = preg_replace('#^https?://#', '', $domain);
        $domain = rtrim($domain, '/');
        $domain = explode('/', $domain)[0];

        if (!preg_match('/^(?=.{1,253}$)([a-z0-9](-?[a-z0-9])*\.)+[a-z]{2,}$/', $domain)) {
            return null;
        }

        return $domain;
    }

    #[Route('/white-label-config/logo', methods: ['POST'])]
    public function uploadLogo(Request $request): JsonResponse
    {
        if (!$this->organizationContext->hasPermission('settings.manage')) {
            return $this->json(['error' => 'Access denied.'], Response::HTTP_FORBIDDEN);
        }

        $org = $this->organizationContext->getOrganization();
        if (!$org) {
            return $this->json(['error' => 'Organization not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->licenseManager->canUseWhiteLabel($org)) {
            return $this->json(['error' => 'White-label is available on the Business plan.'], Response::HTTP_FORBIDDEN);
        }

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

        $config = $this->configRepository->findByOrganization($org);
        if (!$config) {
            $config = new WhiteLabelConfig();
            $config->setOrganization($org);
            $this->entityManager->persist($config);
        }

        $oldPath = $config->getLogoPath();
        if ($oldPath) {
            try {
                if ($this->platformStorage->fileExists($oldPath)) {
                    $this->platformStorage->delete($oldPath);
                }
            } catch (\Throwable) {}
        }

        $ext = $file->guessExtension() ?: 'png';
        $logoPath = sprintf('white-label/%s/logo.%s', $org->getId(), $ext);
        $this->platformStorage->write($logoPath, file_get_contents($file->getPathname()));

        $config->setLogoPath($logoPath);
        $this->entityManager->flush();

        return $this->json(['data' => $this->serialize($config), 'message' => 'Logo uploaded.']);
    }

    #[Route('/white-label-config/logo', methods: ['DELETE'])]
    public function deleteLogo(): JsonResponse
    {
        if (!$this->organizationContext->hasPermission('settings.manage')) {
            return $this->json(['error' => 'Access denied.'], Response::HTTP_FORBIDDEN);
        }

        $org = $this->organizationContext->getOrganization();
        if (!$org) {
            return $this->json(['error' => 'Organization not found.'], Response::HTTP_NOT_FOUND);
        }

        $config = $this->configRepository->findByOrganization($org);
        $logoPath = $config?->getLogoPath();
        if ($config && $logoPath) {
            try {
                if ($this->platformStorage->fileExists($logoPath)) {
                    $this->platformStorage->delete($logoPath);
                }
            } catch (\Throwable) {}
            $config->setLogoPath(null);
            $this->entityManager->flush();
        }

        return $this->json(['message' => 'Logo removed.']);
    }

    #[Route('/white-label/logo', methods: ['GET'])]
    public function getLogo(): Response
    {
        $org = $this->organizationContext->getOrganization();
        if (!$org) {
            return $this->json(['error' => 'Organization not found.'], Response::HTTP_NOT_FOUND);
        }

        $config = $this->configRepository->findByOrganization($org);
        $logoPath = $config?->getLogoPath();
        if (!$logoPath) {
            return $this->json(['error' => 'No logo.'], Response::HTTP_NOT_FOUND);
        }

        try {
            if (!$this->platformStorage->fileExists($logoPath)) {
                return $this->json(['error' => 'Logo file not found.'], Response::HTTP_NOT_FOUND);
            }
            $content = $this->platformStorage->read($logoPath);
            $mimeType = $this->platformStorage->mimeType($logoPath);
        } catch (\Throwable) {
            return $this->json(['error' => 'Could not read logo.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new Response($content, 200, [
            'Content-Type' => $mimeType,
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    private function serialize(?WhiteLabelConfig $config): ?array
    {
        if (!$config) {
            return null;
        }

        $domain = $config->getCustomDomain();

        return [
            'id' => (string) $config->getId(),
            'enabled' => $config->isEnabled(),
            'appName' => $config->getAppName(),
            'logoUrl' => $config->getLogoPath() ? '/v1/white-label/logo' : null,
            'primaryColor' => $config->getPrimaryColor(),
            'removeBranding' => $config->isRemoveBranding(),
            'customDomain' => $domain,
            'customDomainVerified' => $config->isCustomDomainVerified(),
            'customDomainVerifiedAt' => $config->getCustomDomainVerifiedAt()?->format('c'),
            'dnsRecord' => ($domain && !$config->isCustomDomainVerified() && $config->getCustomDomainToken())
                ? ['name' => '_storno-verify.' . $domain, 'type' => 'TXT', 'value' => $config->getCustomDomainToken()]
                : null,
        ];
    }
}
