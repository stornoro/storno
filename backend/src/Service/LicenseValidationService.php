<?php

namespace App\Service;

use App\Entity\Organization;
use App\Repository\CompanyRepository;
use App\Repository\OrganizationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Self-hosted license client — validates the license key and syncs the plan.
 *
 * JWT licenses are validated entirely offline (RSA signature verification).
 * Legacy hex keys validate against the SaaS server with a 7-day cache grace period.
 *
 * Only active when LICENSE_KEY is set (self-hosted mode).
 * When empty (SaaS mode), all methods are no-ops.
 */
class LicenseValidationService
{
    private const CACHE_KEY = 'license_validation';
    private const VERSION_CACHE_KEY = 'version_check';
    private const CACHE_TTL = 86400; // 24 hours
    private const VERSION_CACHE_TTL = 21600; // 6 hours
    private const GRACE_PERIOD = 604800; // 7 days

    private ?array $jwtCache = null;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly OrganizationRepository $organizationRepository,
        private readonly CompanyRepository $companyRepository,
        private readonly EntityManagerInterface $em,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly JwtLicenseDecoder $jwtDecoder,
        private readonly string $licenseKey,
        private readonly string $licenseServerUrl,
        private readonly string $frontendUrl,
    ) {}

    /**
     * Is this instance running in self-hosted mode?
     */
    public function isSelfHosted(): bool
    {
        return !empty($this->licenseKey);
    }

    /**
     * Is the license key a JWT (offline) license?
     */
    public function isJwtLicense(): bool
    {
        return $this->isSelfHosted() && $this->jwtDecoder->isJwtLicense($this->licenseKey);
    }

    /**
     * Validate a JWT license offline. Returns decoded claims or null.
     * Result is memoized per request to avoid repeated RSA verification.
     */
    public function validateJwtLicense(): ?array
    {
        if ($this->jwtCache !== null) {
            return $this->jwtCache ?: null;
        }

        if (!$this->isJwtLicense()) {
            $this->jwtCache = [];
            return null;
        }

        $claims = $this->jwtDecoder->decode($this->licenseKey);
        if (!$claims) {
            $this->logger->warning('JWT license validation failed — invalid signature or malformed token', [
                'reason' => 'invalid_signature_or_format',
                'timestamp' => (new \DateTimeImmutable())->format('c'),
            ]);
            $this->jwtCache = [];
            return null;
        }

        // Log expired JWT licenses at WARNING level with full context
        if ($claims['_expired'] ?? false) {
            $this->logger->warning('JWT license validation failed — license has expired', [
                'reason' => 'expired',
                'organizationId' => $claims['sub'] ?? null,
                'organizationName' => $claims['orgName'] ?? null,
                'plan' => $claims['plan'] ?? 'unknown',
                'expiredAt' => isset($claims['exp']) ? date('c', (int) $claims['exp']) : null,
                'timestamp' => (new \DateTimeImmutable())->format('c'),
            ]);
        }

        $this->logger->info('JWT license validated offline', [
            'plan' => $claims['plan'] ?? 'unknown',
            'orgName' => $claims['orgName'] ?? null,
            'expired' => $claims['_expired'] ?? false,
        ]);

        $this->jwtCache = $claims;
        return $claims;
    }

    /**
     * Get the SaaS billing URL (for redirecting self-hosted users to upgrade).
     */
    public function getBillingUrl(): ?string
    {
        if (!$this->isSelfHosted()) {
            return null;
        }

        $cached = $this->getCachedLicense();
        return $cached['billingUrl'] ?? ($this->licenseServerUrl . '/settings/billing');
    }

    /**
     * Validate the license key against the SaaS server.
     * For JWT licenses, validates offline instead of phoning home.
     * Returns the license data or null on failure.
     */
    public function validate(): ?array
    {
        if (!$this->isSelfHosted()) {
            return null;
        }

        // JWT licenses are validated offline — no phone-home
        if ($this->isJwtLicense()) {
            $claims = $this->validateJwtLicense();
            if (!$claims) {
                return null;
            }

            return [
                'valid' => !($claims['_expired'] ?? false),
                'plan' => $claims['plan'] ?? LicenseManager::PLAN_STARTER,
                'features' => $claims['features'] ?? [],
                'organizationId' => $claims['sub'] ?? null,
                'organizationName' => $claims['orgName'] ?? null,
            ];
        }

        try {
            $response = $this->httpClient->request('POST', $this->licenseServerUrl . '/api/v1/licensing/validate', [
                'json' => [
                    'licenseKey' => $this->licenseKey,
                    'instanceUrl' => $this->frontendUrl,
                    'metrics' => $this->getInstanceMetrics(),
                ],
                'timeout' => 10,
            ]);

            if ($response->getStatusCode() !== 200) {
                $body = json_decode($response->getContent(false), true);
                $this->logger->warning('License validation failed — server rejected the license key', [
                    'reason' => 'server_rejection',
                    'httpStatus' => $response->getStatusCode(),
                    'error' => $body['error'] ?? 'Unknown error',
                    'timestamp' => (new \DateTimeImmutable())->format('c'),
                ]);

                return $this->getCachedLicenseWithGrace();
            }

            $data = $response->toArray();

            if ($data['valid'] ?? false) {
                // Cache the successful response
                $this->cache->delete(self::CACHE_KEY);
                $this->cache->get(self::CACHE_KEY, function (ItemInterface $item) use ($data) {
                    $item->expiresAfter(self::GRACE_PERIOD); // Store for grace period
                    return array_merge($data, ['cachedAt' => time()]);
                });

                $this->logger->info('License validated successfully', [
                    'plan' => $data['plan'],
                    'organizationName' => $data['organizationName'] ?? null,
                ]);
            }

            return $data;
        } catch (\Throwable $e) {
            $this->logger->error('License validation request failed', [
                'error' => $e->getMessage(),
            ]);

            return $this->getCachedLicenseWithGrace();
        }
    }

    /**
     * Validate and sync the plan to the local Organization entity.
     * Called on app startup and periodically by a scheduled command.
     */
    public function syncLicense(): void
    {
        if (!$this->isSelfHosted()) {
            return;
        }

        // JWT licenses: sync plan from claims, expired → starter fallback
        if ($this->isJwtLicense()) {
            $claims = $this->validateJwtLicense();
            $orgs = $this->organizationRepository->findAll();
            if (empty($orgs)) {
                return;
            }
            $org = $orgs[0];

            if ($claims && !($claims['_expired'] ?? false)) {
                $plan = $claims['plan'] ?? LicenseManager::PLAN_STARTER;
                $org->setPlan($plan);
                $this->logger->info('JWT license synced', ['plan' => $plan]);
            } else {
                $org->setPlan(LicenseManager::PLAN_STARTER);
                $this->logger->warning('JWT license expired or invalid during sync — falling back to starter plan', [
                    'reason' => $claims ? 'expired' : 'invalid_or_missing',
                    'organizationId' => (string) $org->getId(),
                    'timestamp' => (new \DateTimeImmutable())->format('c'),
                ]);
            }

            $this->em->flush();
            return;
        }

        $data = $this->validate();
        if (!$data || !($data['valid'] ?? false)) {
            $this->logger->warning('License sync failed — using cached or free plan');
            return;
        }

        $orgs = $this->organizationRepository->findAll();
        if (empty($orgs)) {
            return;
        }

        $plan = $data['plan'] ?? LicenseManager::PLAN_EXPIRED;

        // Identify the licensed organization from server response
        $licensedOrgId = $data['organizationId'] ?? null;
        $licensedOrg = null;

        if ($licensedOrgId) {
            foreach ($orgs as $org) {
                if ((string) $org->getId() === $licensedOrgId) {
                    $licensedOrg = $org;
                    break;
                }
            }
        }

        // Fall back to first org if server didn't specify (backwards compat)
        if (!$licensedOrg) {
            $licensedOrg = $orgs[0];
        }

        // Force-correct the licensed org's plan to match server response (tamper detection)
        $licensedOrg->setPlan($plan);

        // Sync subscription status for display purposes
        if (isset($data['currentPeriodEnd'])) {
            $licensedOrg->setCurrentPeriodEnd(new \DateTimeImmutable($data['currentPeriodEnd']));
        }

        // Downgrade ALL other organizations to free
        foreach ($orgs as $org) {
            if ($org !== $licensedOrg && $org->getPlan() !== LicenseManager::PLAN_EXPIRED) {
                $this->logger->warning('Downgrading unauthorized organization to free plan', [
                    'organization' => (string) $org->getId(),
                    'previousPlan' => $org->getPlan(),
                ]);
                $org->setPlan(LicenseManager::PLAN_EXPIRED);
            }
        }

        $this->em->flush();

        $this->logger->info('License synced to local organization', [
            'organization' => (string) $licensedOrg->getId(),
            'plan' => $plan,
        ]);
    }

    /**
     * Check if a newer version of Storno.ro is available.
     * Fetches from the SaaS server and caches for 6 hours.
     */
    public function checkForUpdate(): ?array
    {
        $projectDir = dirname(__DIR__, 2);
        $versionFile = $projectDir . '/VERSION.txt';
        $currentVersion = is_file($versionFile) ? trim(file_get_contents($versionFile)) : 'unknown';
        try {
            return $this->cache->get(self::VERSION_CACHE_KEY, function (ItemInterface $item) use ($currentVersion) {
                $item->expiresAfter(self::VERSION_CACHE_TTL);

                try {
                    $response = $this->httpClient->request('GET', $this->licenseServerUrl . '/api/v1/version', [
                        'timeout' => 5,
                    ]);

                    if ($response->getStatusCode() !== 200) {
                        // Return current-only info on failure
                        return [
                            'currentVersion' => $currentVersion,
                            'latestVersion' => $currentVersion,
                            'updateAvailable' => false,
                            'downloadUrl' => null,
                        ];
                    }

                    $data = $response->toArray();
                    $latestVersion = $data['version'] ?? $currentVersion;

                    return [
                        'currentVersion' => $currentVersion,
                        'latestVersion' => $latestVersion,
                        'updateAvailable' => version_compare($latestVersion, $currentVersion, '>'),
                        'downloadUrl' => $data['downloadUrl'] ?? null,
                    ];
                } catch (\Throwable $e) {
                    $this->logger->warning('Version check failed', ['error' => $e->getMessage()]);

                    return [
                        'currentVersion' => $currentVersion,
                        'latestVersion' => $currentVersion,
                        'updateAvailable' => false,
                        'downloadUrl' => null,
                    ];
                }
            });
        } catch (\Throwable $e) {
            $this->logger->error('Version check cache error', ['error' => $e->getMessage()]);

            return [
                'currentVersion' => $currentVersion,
                'latestVersion' => $currentVersion,
                'updateAvailable' => false,
                'downloadUrl' => null,
            ];
        }
    }

    /**
     * Collect instance metrics to report to the SaaS server.
     */
    private function getInstanceMetrics(): array
    {
        $conn = $this->em->getConnection();

        $orgCount = (int) $conn->fetchOne('SELECT COUNT(*) FROM organization');
        $userCount = (int) $conn->fetchOne('SELECT COUNT(*) FROM user WHERE active = 1');
        $companyCount = (int) $conn->fetchOne('SELECT COUNT(*) FROM company');

        $firstOfMonth = (new \DateTimeImmutable('first day of this month'))->format('Y-m-d');
        $invoicesThisMonth = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM invoice WHERE created_at >= :since',
            ['since' => $firstOfMonth]
        );

        return [
            'orgCount' => $orgCount,
            'userCount' => $userCount,
            'companyCount' => $companyCount,
            'invoicesThisMonth' => $invoicesThisMonth,
        ];
    }

    /**
     * Get cached license data, or null if cache is expired (beyond grace period).
     */
    private function getCachedLicense(): ?array
    {
        try {
            return $this->cache->get(self::CACHE_KEY, function () {
                return null;
            });
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Get cached license if within grace period, otherwise null.
     */
    private function getCachedLicenseWithGrace(): ?array
    {
        $cached = $this->getCachedLicense();
        if (!$cached) {
            return null;
        }

        $cachedAt = $cached['cachedAt'] ?? 0;
        if (time() - $cachedAt > self::GRACE_PERIOD) {
            $this->logger->warning('License cache expired beyond grace period');
            return null;
        }

        $this->logger->info('Using cached license data (server unreachable)', [
            'plan' => $cached['plan'] ?? 'unknown',
            'cachedAt' => date('c', $cachedAt),
        ]);

        return $cached;
    }
}
