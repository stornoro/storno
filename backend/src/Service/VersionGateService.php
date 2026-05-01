<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\AppVersionOverrideRepository;

/**
 * Computes the upgrade tier a client should be placed in based on its
 * platform and reported version. The contract returned to the client:
 *
 *   tier = "blocking"      → client < min, must update before continuing
 *   tier = "recommended"   → min ≤ client < latest, prompt with skippable modal
 *   tier = "ok"            → client ≥ latest, render nothing
 *   tier = "unknown"       → client did not report a version, render nothing
 *
 * The fourth tier exists so unauthenticated /version probes (no version
 * passed) get a deterministic shape without forcing a "recommended" outcome.
 *
 * Effective metadata is computed by merging DB-stored overrides
 * (admin-controlled, runtime-mutable) on top of config/version.yaml
 * defaults. Each field is overridden independently so admins can flip
 * just `min` for a critical update and leave `latest`/`storeUrl` alone.
 */
final class VersionGateService
{
    public const TIER_BLOCKING = 'blocking';
    public const TIER_RECOMMENDED = 'recommended';
    public const TIER_OK = 'ok';
    public const TIER_UNKNOWN = 'unknown';

    /**
     * @param array<string, mixed> $versionMetadata
     */
    public function __construct(
        private readonly array $versionMetadata,
        private readonly ?AppVersionOverrideRepository $overrideRepository = null,
    ) {
    }

    /**
     * @return array{
     *   tier: string,
     *   latest: string,
     *   min: string,
     *   storeUrl: ?string,
     *   releaseNotesUrl: ?string,
     *   message: ?array<string, string>,
     * }|null
     */
    public function evaluate(string $platform, ?string $clientVersion): ?array
    {
        $config = $this->effectiveConfigFor($platform);
        if ($config === null) {
            return null;
        }

        $latest = (string) ($config['latest'] ?? '0.0.0');
        $min = (string) ($config['min'] ?? '0.0.0');

        return [
            'tier' => $this->resolveTier($clientVersion, $min, $latest),
            'latest' => $latest,
            'min' => $min,
            'storeUrl' => isset($config['storeUrl']) ? (string) $config['storeUrl'] : null,
            'releaseNotesUrl' => isset($config['releaseNotesUrl']) ? (string) $config['releaseNotesUrl'] : null,
            'message' => $this->normalizeMessage($config['message'] ?? null),
        ];
    }

    /**
     * Returns the merged metadata for a platform — YAML defaults with
     * any DB overrides applied. Used by both the gate evaluator and the
     * admin endpoint that reports the current effective state.
     *
     * @return array<string, mixed>|null
     */
    public function effectiveConfigFor(string $platform): ?array
    {
        $platforms = $this->versionMetadata['mobile'] ?? [];
        if (!isset($platforms[$platform]) || !is_array($platforms[$platform])) {
            return null;
        }

        $config = $platforms[$platform];

        if ($this->overrideRepository === null) {
            return $config;
        }

        $override = $this->overrideRepository->findByPlatform($platform);
        if ($override === null) {
            return $config;
        }

        if ($override->getMinOverride() !== null) {
            $config['min'] = $override->getMinOverride();
        }
        if ($override->getLatestOverride() !== null) {
            $config['latest'] = $override->getLatestOverride();
        }
        if ($override->getStoreUrlOverride() !== null) {
            $config['storeUrl'] = $override->getStoreUrlOverride();
        }
        if ($override->getReleaseNotesUrlOverride() !== null) {
            $config['releaseNotesUrl'] = $override->getReleaseNotesUrlOverride();
        }
        if ($override->getMessageOverride() !== null) {
            $config['message'] = $override->getMessageOverride();
        }

        return $config;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function defaultConfigFor(string $platform): ?array
    {
        $platforms = $this->versionMetadata['mobile'] ?? [];
        if (!isset($platforms[$platform]) || !is_array($platforms[$platform])) {
            return null;
        }
        return $platforms[$platform];
    }

    private function resolveTier(?string $clientVersion, string $min, string $latest): string
    {
        $client = $this->normalizeVersion($clientVersion);
        if ($client === null) {
            return self::TIER_UNKNOWN;
        }
        if (version_compare($client, $this->normalizeVersion($min) ?? '0.0.0', '<')) {
            return self::TIER_BLOCKING;
        }
        if (version_compare($client, $this->normalizeVersion($latest) ?? '0.0.0', '<')) {
            return self::TIER_RECOMMENDED;
        }
        return self::TIER_OK;
    }

    /**
     * Strip build metadata (`+build123`) and surrounding whitespace so PHP's
     * version_compare receives a clean PEP 440-ish string. Pre-release tags
     * (`-beta`) are preserved — version_compare handles them natively.
     */
    private function normalizeVersion(?string $version): ?string
    {
        if (!is_string($version)) {
            return null;
        }
        $trimmed = trim($version);
        if ($trimmed === '') {
            return null;
        }
        $plus = strpos($trimmed, '+');
        if ($plus !== false) {
            $trimmed = substr($trimmed, 0, $plus);
        }
        return $trimmed;
    }

    /**
     * @param mixed $message
     * @return array<string, string>|null
     */
    private function normalizeMessage(mixed $message): ?array
    {
        if (!is_array($message) || $message === []) {
            return null;
        }
        $out = [];
        foreach ($message as $locale => $text) {
            if (is_string($locale) && is_string($text) && $text !== '') {
                $out[$locale] = $text;
            }
        }
        return $out === [] ? null : $out;
    }
}
