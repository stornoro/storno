<?php

namespace App\Service\Cache;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

/**
 * Caches the aggregates a company's screens recompute on every request (dashboard figures,
 * list totals, the currencies in use). Those queries read every invoice of the company, so
 * past a few hundred thousand invoices each one costs seconds.
 *
 * Every entry is stored under the company's current version. A write to one of the company's
 * documents moves the version (CompanyStatsInvalidationListener), which orphans all its
 * entries at once. Writes that bypass the ORM (DBAL statements, bulk DQL updates) must call
 * invalidate() themselves. The short TTL bounds how stale a figure can get if one forgets.
 *
 * Cached values must be plain arrays or scalars. The cache is off in the test environment,
 * where fixtures are written and read back within the same second.
 */
class CompanyStatsCache
{
    public const DEFAULT_TTL = 120;

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly string $env,
    ) {}

    /**
     * @template T
     *
     * @param callable(): T $compute
     *
     * @return T
     */
    public function get(string $companyId, string $key, callable $compute, int $ttl = self::DEFAULT_TTL): mixed
    {
        if ($this->env === 'test') {
            return $compute();
        }

        try {
            $item = $this->cache->getItem('cstats_' . hash('xxh128', $companyId . '|' . $this->version($companyId) . '|' . $key));
            if ($item->isHit()) {
                return $item->get();
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Company stats cache read failed: {error}', ['error' => $e->getMessage()]);

            return $compute();
        }

        $value = $compute();

        try {
            $item->set($value);
            $item->expiresAfter($ttl);
            $this->cache->save($item);
        } catch (\Throwable $e) {
            $this->logger->warning('Company stats cache write failed: {error}', ['error' => $e->getMessage()]);
        }

        return $value;
    }

    /** Drops every cached aggregate of the company. */
    public function invalidate(string $companyId): void
    {
        if ($this->env === 'test') {
            return;
        }

        try {
            $item = $this->cache->getItem($this->versionKey($companyId));
            $item->set(bin2hex(random_bytes(6)));
            $this->cache->save($item);
        } catch (\Throwable $e) {
            $this->logger->warning('Company stats cache invalidation failed: {error}', ['error' => $e->getMessage()]);
        }
    }

    private function version(string $companyId): string
    {
        $item = $this->cache->getItem($this->versionKey($companyId));
        if ($item->isHit()) {
            return (string) $item->get();
        }

        $version = bin2hex(random_bytes(6));
        $item->set($version);
        $this->cache->save($item);

        return $version;
    }

    private function versionKey(string $companyId): string
    {
        return 'cstats_v_' . hash('xxh128', $companyId);
    }
}
