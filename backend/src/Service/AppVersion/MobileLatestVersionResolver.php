<?php

declare(strict_types=1);

namespace App\Service\AppVersion;

/**
 * Decides which version the mobile update gate should advertise as "latest".
 *
 * The store listing alone is not trustworthy: App Store Connect lets the
 * marketing version differ from what the binary reports about itself
 * (Constants.expoConfig.version), and advertising a version no installed
 * app can ever reach would nag fully-updated users forever. So the store
 * version is only accepted once at least one real device has reported it
 * through telemetry; until then the highest reported version wins.
 */
final class MobileLatestVersionResolver
{
    /**
     * @return array{latest: ?string, reason: string}
     */
    public function resolve(?string $storeVersion, ?string $highestReportedVersion, string $effectiveLatest, string $effectiveMin): array
    {
        $store = $this->normalize($storeVersion);
        $reported = $this->normalize($highestReportedVersion);
        $current = $this->normalize($effectiveLatest) ?? '0.0.0';
        $min = $this->normalize($effectiveMin) ?? '0.0.0';

        if ($store === null && $reported === null) {
            return ['latest' => null, 'reason' => 'no store version and no device reports'];
        }

        $candidate = $store;
        $reason = 'store version confirmed by devices';
        if ($store !== null && $reported !== null && version_compare($reported, $store, '<')) {
            $candidate = $reported;
            $reason = sprintf('store lists %s but the newest installed binary reports %s; waiting for a device on %s', $store, $reported, $store);
        } elseif ($store === null) {
            $candidate = $reported;
            $reason = 'store unavailable, using the highest device-reported version';
        } elseif ($reported === null) {
            // Store is newer than anything ever reported: not yet proven by a device.
            return ['latest' => null, 'reason' => sprintf('store lists %s but no device has reported it yet', $store)];
        }

        if ($candidate === null || version_compare($candidate, $current, '<=')) {
            return ['latest' => null, 'reason' => sprintf('effective latest %s already up to date (%s)', $current, $reason)];
        }
        if (version_compare($candidate, $min, '<')) {
            return ['latest' => null, 'reason' => sprintf('candidate %s is below the minimum supported %s', $candidate, $min)];
        }

        return ['latest' => $candidate, 'reason' => $reason];
    }

    public function normalize(?string $version): ?string
    {
        if ($version === null) {
            return null;
        }
        $v = trim($version);
        if (preg_match('/(\d+)\.(\d+)(?:\.(\d+))?/', $v, $m)) {
            return sprintf('%d.%d.%d', (int) $m[1], (int) $m[2], (int) ($m[3] ?? 0));
        }

        return null;
    }
}
