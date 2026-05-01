<?php

declare(strict_types=1);

namespace App\Service;

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
    public function __construct(private readonly array $versionMetadata)
    {
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
        $platforms = $this->versionMetadata['mobile'] ?? [];
        if (!isset($platforms[$platform]) || !is_array($platforms[$platform])) {
            return null;
        }

        $config = $platforms[$platform];
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
