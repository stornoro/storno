<?php

namespace App\Controller\Api\V1;

use App\Repository\LicenseKeyRepository;
use App\Security\OrganizationContext;
use App\Security\Permission;
use App\Service\LicenseManager;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Licensing API — exposed by the SaaS instance for self-hosted Docker instances.
 *
 * Self-hosted instances call POST /api/v1/licensing/validate with their license key
 * to get their current plan and features. No authentication needed — the license key
 * is the credential.
 *
 * License key management (create/list/revoke) requires authentication + owner permission.
 */
class LicensingController extends AbstractController
{
    public function __construct(
        private readonly LicenseKeyRepository $licenseKeyRepository,
        private readonly LicenseManager $licenseManager,
        private readonly OrganizationContext $organizationContext,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly string $frontendUrl,
        private readonly string $jwtPrivateKeyPath,
        private readonly string $jwtIssuer,
    ) {}

    /**
     * Validate a license key — called by self-hosted instances (no auth required).
     * Returns the plan, features, and subscription status.
     */
    #[Route('/api/v1/licensing/validate', name: 'licensing_validate', methods: ['POST'])]
    public function validate(Request $request, RateLimiterFactory $licenseValidateLimiter): JsonResponse
    {
        $limiter = $licenseValidateLimiter->create($request->getClientIp());
        if (!$limiter->consume()->isAccepted()) {
            return $this->json(['error' => 'Too many requests.'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $data = json_decode($request->getContent(), true);
        $key = $data['licenseKey'] ?? null;

        if (!$key) {
            return $this->json(['error' => 'licenseKey is required'], Response::HTTP_BAD_REQUEST);
        }

        $licenseKey = $this->licenseKeyRepository->findByKey($key);
        if (!$licenseKey) {
            return $this->json([
                'valid' => false,
                'error' => 'Invalid or revoked license key',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $org = $licenseKey->getOrganization();
        if (!$org || !$org->isActive()) {
            return $this->json([
                'valid' => false,
                'error' => 'Organization is inactive',
            ], Response::HTTP_FORBIDDEN);
        }

        // Update last validated timestamp
        $licenseKey->setLastValidatedAt(new \DateTimeImmutable());

        // Record instance info if provided
        if (!empty($data['instanceName'])) {
            $licenseKey->setInstanceName($data['instanceName']);
        }
        if (!empty($data['instanceUrl'])) {
            $licenseKey->setInstanceUrl($data['instanceUrl']);
        }

        // Store instance metrics if provided
        $metrics = $data['metrics'] ?? null;
        if (is_array($metrics)) {
            $licenseKey->setInstanceMetrics($metrics);
        }

        $plan = $this->licenseManager->getEffectivePlan($org);
        $features = $this->licenseManager->getFeatures($org);

        // Self-hosting requires a plan that includes the selfHostingLicense feature
        if (!($features['selfHostingLicense'] ?? false)) {
            $this->logger->warning('License validation rejected — plan does not include self-hosting', [
                'licenseKeyId' => (string) $licenseKey->getId(),
                'organization' => (string) $org->getId(),
                'plan' => $plan,
            ]);

            return $this->json([
                'valid' => false,
                'error' => 'Your current plan does not include self-hosting. Please upgrade to Business.',
                'plan' => $plan,
                'billingUrl' => $this->frontendUrl . '/settings/billing',
            ], Response::HTTP_FORBIDDEN);
        }

        // Check metrics against plan limits for violations
        $violations = [];
        if (is_array($metrics)) {
            $violations = $this->checkMetricsViolations($metrics, $features);
            $licenseKey->setLastViolations($violations ?: null);
        }

        $this->em->flush();

        $response = [
            'valid' => true,
            'plan' => $plan,
            'features' => $features,
            'organizationId' => (string) $org->getId(),
            'organizationName' => $org->getName(),
            'violations' => $violations,
        ];

        // Include subscription expiry so the self-hosted instance knows when to re-check
        if ($org->getCurrentPeriodEnd()) {
            $response['currentPeriodEnd'] = $org->getCurrentPeriodEnd()->format('c');
        }

        if ($org->isTrialActive()) {
            $response['trialEndsAt'] = $org->getTrialEndsAt()->format('c');
            $daysLeft = (int) (new \DateTimeImmutable())->diff($org->getTrialEndsAt())->days;
            $response['trialDaysLeft'] = $daysLeft;
        }

        // Billing URL for self-hosted instances to redirect users for upgrades
        $response['billingUrl'] = $this->frontendUrl . '/settings/billing';

        $this->logger->info('License validated', [
            'licenseKeyId' => (string) $licenseKey->getId(),
            'organization' => (string) $org->getId(),
            'plan' => $plan,
            'instanceName' => $licenseKey->getInstanceName(),
        ]);

        return $this->json($response);
    }

    /**
     * Generate a new license key for the current organization (owner only).
     */
    #[Route('/api/v1/licensing/keys', name: 'licensing_keys_create', methods: ['POST'])]
    public function createKey(Request $request): JsonResponse
    {
        $org = $this->organizationContext->getOrganization();
        if (!$org) {
            return $this->json(['error' => 'Organization not found'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::ORG_MANAGE_BILLING)) {
            return $this->json(['error' => 'Permission denied'], Response::HTTP_FORBIDDEN);
        }

        $features = $this->licenseManager->getFeatures($org);
        if (!($features['selfHostingLicense'] ?? false)) {
            return $this->json([
                'error' => 'Self-hosting license is not available on your current plan. Please upgrade to Business.',
            ], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);

        $licenseKey = new \App\Entity\LicenseKey();
        $licenseKey->setOrganization($org);
        $licenseKey->setInstanceName($data['instanceName'] ?? null);

        $this->em->persist($licenseKey);
        $this->em->flush();

        return $this->json([
            'id' => (string) $licenseKey->getId(),
            'licenseKey' => $licenseKey->getLicenseKey(),
            'instanceName' => $licenseKey->getInstanceName(),
            'active' => $licenseKey->isActive(),
            'createdAt' => $licenseKey->getCreatedAt()->format('c'),
        ], Response::HTTP_CREATED);
    }

    /**
     * List license keys for the current organization (owner only).
     */
    #[Route('/api/v1/licensing/keys', name: 'licensing_keys_list', methods: ['GET'])]
    public function listKeys(): JsonResponse
    {
        $org = $this->organizationContext->getOrganization();
        if (!$org) {
            return $this->json(['error' => 'Organization not found'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::ORG_MANAGE_BILLING)) {
            return $this->json(['error' => 'Permission denied'], Response::HTTP_FORBIDDEN);
        }

        $keys = $this->licenseKeyRepository->findByOrganization($org);

        return $this->json([
            'keys' => array_map(fn ($k) => [
                'id' => (string) $k->getId(),
                'licenseKey' => substr($k->getLicenseKey(), 0, 8) . '...' . substr($k->getLicenseKey(), -8),
                'instanceName' => $k->getInstanceName(),
                'instanceUrl' => $k->getInstanceUrl(),
                'active' => $k->isActive(),
                'lastValidatedAt' => $k->getLastValidatedAt()?->format('c'),
                'activatedAt' => $k->getActivatedAt()?->format('c'),
                'createdAt' => $k->getCreatedAt()->format('c'),
                'instanceMetrics' => $k->getInstanceMetrics(),
                'lastViolations' => $k->getLastViolations(),
            ], $keys),
        ]);
    }

    /**
     * Revoke a license key (owner only).
     */
    #[Route('/api/v1/licensing/keys/{id}', name: 'licensing_keys_revoke', methods: ['DELETE'])]
    public function revokeKey(string $id): JsonResponse
    {
        $org = $this->organizationContext->getOrganization();
        if (!$org) {
            return $this->json(['error' => 'Organization not found'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::ORG_MANAGE_BILLING)) {
            return $this->json(['error' => 'Permission denied'], Response::HTTP_FORBIDDEN);
        }

        $licenseKey = $this->licenseKeyRepository->find($id);
        if (!$licenseKey || $licenseKey->getOrganization() !== $org) {
            return $this->json(['error' => 'License key not found'], Response::HTTP_NOT_FOUND);
        }

        $licenseKey->setActive(false);
        $this->em->flush();

        return $this->json(['status' => 'revoked']);
    }

    /**
     * Generate a signed JWT license token for a license key (owner only).
     * The JWT allows offline validation on self-hosted instances.
     */
    #[Route('/api/v1/licensing/keys/{id}/jwt', name: 'licensing_keys_jwt', methods: ['POST'])]
    public function generateJwt(string $id): JsonResponse
    {
        $org = $this->organizationContext->getOrganization();
        if (!$org) {
            return $this->json(['error' => 'Organization not found'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::ORG_MANAGE_BILLING)) {
            return $this->json(['error' => 'Permission denied'], Response::HTTP_FORBIDDEN);
        }

        $licenseKey = $this->licenseKeyRepository->find($id);
        if (!$licenseKey || $licenseKey->getOrganization() !== $org) {
            return $this->json(['error' => 'License key not found'], Response::HTTP_NOT_FOUND);
        }

        if (!$licenseKey->isActive()) {
            return $this->json(['error' => 'License key is revoked'], Response::HTTP_FORBIDDEN);
        }

        if (empty($this->jwtPrivateKeyPath) || !is_file($this->jwtPrivateKeyPath)) {
            return $this->json(['error' => 'JWT signing is not configured on this server'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $privateKeyPem = file_get_contents($this->jwtPrivateKeyPath);
        $privateKey = openssl_pkey_get_private($privateKeyPem);
        if (!$privateKey) {
            return $this->json(['error' => 'Failed to load signing key'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $plan = $this->licenseManager->getEffectivePlan($org);
        $features = $this->licenseManager->getFeatures($org);

        $now = time();
        $claims = [
            'iss' => $this->jwtIssuer,
            'sub' => (string) $org->getId(),
            'plan' => $plan,
            'features' => $features,
            'orgName' => $org->getName(),
            'iat' => $now,
            'exp' => $now + (365 * 86400), // 1 year
        ];

        $header = $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $payload = $this->base64UrlEncode(json_encode($claims));
        $data = $header . '.' . $payload;

        openssl_sign($data, $signature, $privateKey, \OPENSSL_ALGO_SHA256);
        $jwt = $data . '.' . $this->base64UrlEncode($signature);

        return $this->json([
            'jwt' => $jwt,
            'expiresAt' => date('c', $claims['exp']),
            'plan' => $plan,
        ]);
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Check instance metrics against plan feature limits.
     * Returns an array of violation descriptions (empty = no violations).
     */
    private function checkMetricsViolations(array $metrics, array $features): array
    {
        $violations = [];

        $orgCount = $metrics['orgCount'] ?? 0;
        if ($orgCount > 1) {
            $violations[] = sprintf('Multiple organizations detected (%d). Self-hosted licenses allow 1.', $orgCount);
        }

        $companyCount = $metrics['companyCount'] ?? 0;
        $maxCompanies = $features['maxCompanies'] ?? 1;
        if ($maxCompanies > 0 && $companyCount > $maxCompanies) {
            $violations[] = sprintf('Company limit exceeded: %d / %d allowed.', $companyCount, $maxCompanies);
        }

        $userCount = $metrics['userCount'] ?? 0;
        $maxUsers = $features['maxUsersPerOrg'] ?? 1;
        if ($maxUsers > 0 && $userCount > $maxUsers) {
            $violations[] = sprintf('User limit exceeded: %d / %d allowed.', $userCount, $maxUsers);
        }

        $invoicesThisMonth = $metrics['invoicesThisMonth'] ?? 0;
        $maxInvoices = $features['maxInvoicesPerMonth'] ?? 0;
        if ($maxInvoices > 0 && $invoicesThisMonth > $maxInvoices) {
            $violations[] = sprintf('Monthly invoice limit exceeded: %d / %d allowed.', $invoicesThisMonth, $maxInvoices);
        }

        return $violations;
    }
}
