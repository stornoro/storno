<?php

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Migrations\DependencyFactory;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class SystemHealthService
{
    private const REQUIRED_EXTENSIONS = [
        'json', 'pdo', 'mbstring', 'openssl', 'curl',
        'xml', 'zip', 'intl', 'gd', 'bcmath',
    ];

    private const MIN_PHP_VERSION = '8.2';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CacheInterface $cache,
        private readonly HttpClientInterface $httpClient,
        private readonly LicenseValidationService $licenseValidationService,
        private readonly DependencyFactory $dependencyFactory,
        private readonly LoggerInterface $logger,
        private readonly string $projectDir,
        private readonly string $env,
        private readonly string $centrifugoApiUrl,
        private readonly string $centrifugoApiKey,
    ) {}

    public function getVersion(): string
    {
        $versionFile = $this->projectDir . '/VERSION.txt';
        if (is_file($versionFile)) {
            return trim(file_get_contents($versionFile));
        }

        return 'unknown';
    }

    public function runAllChecks(): array
    {
        $checks = [];
        $overallOk = true;

        $checks['php'] = $this->checkPhp();
        $checks['extensions'] = $this->checkExtensions();
        $checks['database'] = $this->checkDatabase();
        $checks['cache'] = $this->checkCache();
        $checks['centrifugo'] = $this->checkCentrifugo();
        $checks['messenger'] = $this->checkMessenger();
        $checks['filesystem'] = $this->checkFilesystem();
        $checks['diskSpace'] = $this->checkDiskSpace();

        foreach ($checks as $check) {
            if (!($check['ok'] ?? false)) {
                $overallOk = false;
                break;
            }
        }

        $version = $this->getVersion();
        $updateInfo = $this->licenseValidationService->checkForUpdate();

        $status = 'healthy';
        if (!$overallOk) {
            // Determine if degraded (non-critical failures) or unhealthy (critical)
            $criticalChecks = ['php', 'database'];
            $criticalFailed = false;
            foreach ($criticalChecks as $key) {
                if (!($checks[$key]['ok'] ?? false)) {
                    $criticalFailed = true;
                    break;
                }
            }
            $status = $criticalFailed ? 'unhealthy' : 'degraded';
        }

        return [
            'status' => $status,
            'version' => $version,
            'latestVersion' => $updateInfo['latestVersion'] ?? null,
            'updateAvailable' => $updateInfo['updateAvailable'] ?? false,
            'environment' => $this->licenseValidationService->isSelfHosted() ? 'selfhost' : 'saas',
            'checks' => $checks,
            'timestamp' => (new \DateTimeImmutable())->format('c'),
        ];
    }

    public function getMinimalStatus(): array
    {
        return [
            'status' => $this->isHealthy() ? 'healthy' : 'unhealthy',
            'version' => $this->getVersion(),
            'timestamp' => (new \DateTimeImmutable())->format('c'),
        ];
    }

    private function isHealthy(): bool
    {
        try {
            $this->em->getConnection()->executeQuery('SELECT 1');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function checkPhp(): array
    {
        $version = PHP_VERSION;
        $ok = version_compare($version, self::MIN_PHP_VERSION, '>=');

        return [
            'ok' => $ok,
            'version' => $version,
            'minimum' => self::MIN_PHP_VERSION,
            'memory_limit' => ini_get('memory_limit'),
        ];
    }

    private function checkExtensions(): array
    {
        $loaded = [];
        $missing = [];

        foreach (self::REQUIRED_EXTENSIONS as $ext) {
            if (extension_loaded($ext)) {
                $loaded[] = $ext;
            } else {
                $missing[] = $ext;
            }
        }

        return [
            'ok' => empty($missing),
            'loaded' => $loaded,
            'missing' => $missing,
        ];
    }

    private function checkDatabase(): array
    {
        try {
            $conn = $this->em->getConnection();
            $conn->executeQuery('SELECT 1');

            $serverVersion = $conn->getNativeConnection()->getAttribute(\PDO::ATTR_SERVER_VERSION);

            // Check pending migrations
            $pendingMigrations = 0;
            try {
                $statusCalculator = $this->dependencyFactory->getMigrationStatusCalculator();
                $newMigrations = $statusCalculator->getNewMigrations();
                $pendingMigrations = count($newMigrations);
            } catch (\Throwable $e) {
                $this->logger->warning('Could not check migration status', ['error' => $e->getMessage()]);
            }

            return [
                'ok' => true,
                'version' => $serverVersion,
                'pendingMigrations' => $pendingMigrations,
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function checkCache(): array
    {
        try {
            $testKey = 'health_check_' . bin2hex(random_bytes(4));
            $this->cache->delete($testKey);
            $value = $this->cache->get($testKey, fn () => 'ok');
            $this->cache->delete($testKey);

            return ['ok' => $value === 'ok'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function checkCentrifugo(): array
    {
        try {
            $response = $this->httpClient->request('POST', $this->centrifugoApiUrl . '/info', [
                'headers' => [
                    'X-API-Key' => $this->centrifugoApiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => new \stdClass(),
                'timeout' => 3,
            ]);

            $info = $response->toArray();
            $clients = $info['result']['nodes'][0]['num_clients'] ?? 0;

            return ['ok' => true, 'clients' => $clients];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function checkMessenger(): array
    {
        try {
            $conn = $this->em->getConnection();

            // Check if messenger_messages table exists
            $schemaManager = $conn->createSchemaManager();
            if (!$schemaManager->tablesExist(['messenger_messages'])) {
                return ['ok' => true, 'pending' => 0, 'failed' => 0];
            }

            $pending = (int) $conn->fetchOne(
                'SELECT COUNT(*) FROM messenger_messages WHERE delivered_at IS NULL'
            );

            $failed = (int) $conn->fetchOne(
                "SELECT COUNT(*) FROM messenger_messages WHERE headers LIKE '%redelivery_count%'"
            );

            return [
                'ok' => true,
                'pending' => $pending,
                'failed' => $failed,
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function checkFilesystem(): array
    {
        $varDir = $this->projectDir . '/var';
        $writable = is_writable($varDir);

        return [
            'ok' => $writable,
            'varWritable' => $writable,
        ];
    }

    private function checkDiskSpace(): array
    {
        $storageDir = $this->projectDir . '/var';
        $freeBytes = @disk_free_space($storageDir);

        if ($freeBytes === false) {
            return ['ok' => false, 'error' => 'Could not determine free disk space'];
        }

        // Consider less than 100MB as problematic
        $ok = $freeBytes > 100 * 1024 * 1024;

        return [
            'ok' => $ok,
            'freeBytes' => (int) $freeBytes,
            'freeHuman' => $this->formatBytes($freeBytes),
        ];
    }

    private function formatBytes(float $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 1) . ' ' . $units[$i];
    }
}
