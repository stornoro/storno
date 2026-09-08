<?php

namespace App\Service\Marketing;

use Symfony\Component\HttpFoundation\Request;

/**
 * Pulls the Meta click identifiers out of a registration request.
 *
 * The frontend sends them in the JSON body under `attribution` (read from the
 * `_fbc` / `_fbp` cookies its attribution plugin sets on the parent domain);
 * cookies on the request itself are the fallback. Values are validated against
 * Meta's documented formats so nothing arbitrary is forwarded.
 */
final class RegistrationAttribution
{
    private const FBC_PATTERN = '/^fb\.[0-9]\.\d{10,16}\.[A-Za-z0-9_\-]{1,512}$/';
    private const FBP_PATTERN = '/^fb\.[0-9]\.\d{10,16}\.\d{1,20}$/';

    /**
     * @return array{fbc: ?string, fbp: ?string, source_url: ?string}
     */
    public static function fromRequest(Request $request, array $payload): array
    {
        $attribution = is_array($payload['attribution'] ?? null) ? $payload['attribution'] : [];

        $fbc = self::pick($attribution['fbc'] ?? null, $request->cookies->get('_fbc'), self::FBC_PATTERN);
        $fbp = self::pick($attribution['fbp'] ?? null, $request->cookies->get('_fbp'), self::FBP_PATTERN);

        // A raw fbclid (ad click landing straight on the register page) is turned
        // into the fbc format Meta expects: fb.1.<ms timestamp>.<fbclid>.
        if ($fbc === null && is_string($attribution['fbclid'] ?? null) && preg_match('/^[A-Za-z0-9_\-]{1,512}$/', $attribution['fbclid'])) {
            $fbc = sprintf('fb.1.%d.%s', (int) (microtime(true) * 1000), $attribution['fbclid']);
        }

        $sourceUrl = null;
        foreach ([$attribution['source_url'] ?? null, $request->headers->get('Referer'), $request->headers->get('Origin')] as $candidate) {
            if (is_string($candidate) && preg_match('~^https?://[^\s]{1,2000}$~', $candidate)) {
                $sourceUrl = $candidate;
                break;
            }
        }

        return ['fbc' => $fbc, 'fbp' => $fbp, 'source_url' => $sourceUrl];
    }

    private static function pick(mixed $fromPayload, ?string $fromCookie, string $pattern): ?string
    {
        foreach ([$fromPayload, $fromCookie] as $value) {
            if (is_string($value) && preg_match($pattern, $value)) {
                return $value;
            }
        }

        return null;
    }
}
