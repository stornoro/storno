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

    private const UTM_KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'];

    /**
     * @return array{fbc: ?string, fbp: ?string, source_url: ?string, utm: array<string, string>, referrer: ?string}
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

        // utm_* values stored by the landing's attribution plugin (first-party
        // cookie, forwarded in the payload). Whitelisted keys, short plain strings.
        $utm = [];
        $rawUtm = is_array($attribution['utm'] ?? null) ? $attribution['utm'] : [];
        foreach ([...self::UTM_KEYS, 'landing'] as $key) {
            $value = $rawUtm[$key] ?? null;
            if (is_string($value) && $value !== '' && mb_strlen($value) <= 200 && !preg_match('/[\x00-\x1f\x7f]/', $value)) {
                $utm[$key] = $value;
            }
        }

        $referrer = $request->headers->get('Referer');
        $referrer = is_string($referrer) && preg_match('~^https?://[^\s]{1,2000}$~', $referrer) ? $referrer : null;

        return ['fbc' => $fbc, 'fbp' => $fbp, 'source_url' => $sourceUrl, 'utm' => $utm, 'referrer' => $referrer];
    }

    /**
     * What gets stored on the account: no raw click ids, only whether an ad
     * click preceded the sign-up, plus the utm parameters and the landing page.
     *
     * @param array{fbc: ?string, fbp: ?string, source_url: ?string, utm: array<string, string>, referrer: ?string} $attribution
     * @return array<string, mixed>|null null when nothing is known (organic, direct)
     */
    public static function forStorage(array $attribution): ?array
    {
        $stored = $attribution['utm'];
        if ($attribution['fbc'] !== null) {
            $stored['meta_click'] = true;
        }
        if ($attribution['referrer'] !== null) {
            $stored['referrer'] = $attribution['referrer'];
        }
        if ($attribution['source_url'] !== null) {
            $stored['source_url'] = $attribution['source_url'];
        }

        return $stored === [] ? null : $stored;
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
