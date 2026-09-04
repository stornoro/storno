<?php

namespace App\Service\Security;

/**
 * Guards every user-supplied URL / host the backend connects to (webhook
 * endpoints, S3-compatible storage endpoints, SMTP hosts, e-invoice
 * intermediaries, ...) against SSRF.
 *
 * A URL is accepted only when it is http(s), carries no credentials, and
 * every address its host resolves to is a public unicast address. Loopback,
 * link-local, RFC1918, CGNAT, ULA, multicast, "this network", IPv4-mapped
 * IPv6 and the docker-compose service names of this stack are rejected.
 *
 * All rejections throw the same generic \InvalidArgumentException so callers
 * cannot be used as a network-probing oracle.
 */
final class OutboundUrlPolicy
{
    public const ERROR_MESSAGE = 'URL is not allowed.';

    private const BLOCKED_HOSTNAMES = [
        'localhost',
        'db',
        'redis',
        'centrifugo',
        'backend',
        'frontend',
        'java-services',
    ];

    private const BLOCKED_SUFFIXES = [
        '.localhost',
        '.internal',
        '.local',
    ];

    /** @var \Closure(string): array<int, string> */
    private readonly \Closure $resolver;

    /**
     * @param \Closure(string): array<int, string>|null $resolver Returns the IP addresses (v4 and v6)
     *        a hostname resolves to. Defaults to the system resolver; injectable for tests.
     */
    public function __construct(?\Closure $resolver = null)
    {
        $this->resolver = $resolver ?? self::systemResolver(...);
    }

    /**
     * Validate a full URL.
     *
     * Options:
     *  - httpsOnly (bool): require the https scheme.
     *  - allowedPorts (int[]): when set, the effective port must be one of these.
     *
     * @return string the trimmed URL, ready to be used
     *
     * @throws \InvalidArgumentException when the URL is not allowed
     */
    public function assertAllowed(string $url, array $opts = []): string
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 2048 || preg_match('/[\s\x00-\x1F\x7F]/', $url)) {
            throw self::denied();
        }

        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw self::denied();
        }

        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'https' && ($scheme !== 'http' || !empty($opts['httpsOnly']))) {
            throw self::denied();
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw self::denied();
        }

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        if (isset($opts['allowedPorts']) && !in_array($port, $opts['allowedPorts'], true)) {
            throw self::denied();
        }

        $this->assertHostAllowed($parts['host']);

        return $url;
    }

    /**
     * Validate a bare hostname or IP literal (e.g. an SMTP host).
     *
     * @return string the normalized (lower-cased, unbracketed) host
     *
     * @throws \InvalidArgumentException when the host is not allowed
     */
    public function assertHostAllowed(string $host): string
    {
        $host = strtolower(trim($host));
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }
        $host = rtrim($host, '.');

        if ($host === '' || strlen($host) > 253 || preg_match('/[\s\/@\\\\]/', $host)) {
            throw self::denied();
        }

        // IP literal — check directly, no DNS involved.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if (self::isForbiddenIp($host)) {
                throw self::denied();
            }

            return $host;
        }

        // Anything that is not a plain hostname (e.g. octal/hex/decimal IPv4 forms) is rejected.
        if (!preg_match('/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))*$/', $host)) {
            throw self::denied();
        }

        if (in_array($host, self::BLOCKED_HOSTNAMES, true)) {
            throw self::denied();
        }
        foreach (self::BLOCKED_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                throw self::denied();
            }
        }

        $addresses = ($this->resolver)($host);
        if (empty($addresses)) {
            throw self::denied();
        }
        foreach ($addresses as $ip) {
            if (!is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP) === false || self::isForbiddenIp($ip)) {
                throw self::denied();
            }
        }

        return $host;
    }

    public static function isForbiddenIp(string $ip): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return true;
        }

        if (strlen($packed) === 4) {
            return self::isForbiddenIpv4($packed);
        }

        return self::isForbiddenIpv6($packed);
    }

    private static function isForbiddenIpv4(string $packed): bool
    {
        $b = array_values(unpack('C4', $packed));

        return $b[0] === 0                                   // 0.0.0.0/8 "this network"
            || $b[0] === 10                                  // 10.0.0.0/8
            || $b[0] === 127                                 // 127.0.0.0/8 loopback
            || ($b[0] === 100 && ($b[1] & 0xC0) === 64)      // 100.64.0.0/10 CGNAT
            || ($b[0] === 169 && $b[1] === 254)              // 169.254.0.0/16 link-local
            || ($b[0] === 172 && ($b[1] & 0xF0) === 16)      // 172.16.0.0/12
            || ($b[0] === 192 && $b[1] === 168)              // 192.168.0.0/16
            || ($b[0] === 192 && $b[1] === 0 && $b[2] === 0) // 192.0.0.0/24 IETF protocol assignments
            || $b[0] >= 224;                                 // 224.0.0.0/4 multicast, 240.0.0.0/4 reserved, broadcast
    }

    private static function isForbiddenIpv6(string $packed): bool
    {
        $b = array_values(unpack('C16', $packed));

        // :: (unspecified) and ::1 (loopback)
        $isZeroPrefix = true;
        for ($i = 0; $i < 15; $i++) {
            if ($b[$i] !== 0) {
                $isZeroPrefix = false;
                break;
            }
        }
        if ($isZeroPrefix && ($b[15] === 0 || $b[15] === 1)) {
            return true;
        }

        // ::ffff:0:0/96 IPv4-mapped — rejected outright.
        $isMapped = true;
        for ($i = 0; $i < 10; $i++) {
            if ($b[$i] !== 0) {
                $isMapped = false;
                break;
            }
        }
        if ($isMapped && (($b[10] === 0xFF && $b[11] === 0xFF) || ($b[10] === 0 && $b[11] === 0))) {
            // ::ffff:a.b.c.d (mapped) and the deprecated ::a.b.c.d (compatible) forms
            return true;
        }

        // 64:ff9b::/96 NAT64 — judge by the embedded IPv4 address.
        if ($b[0] === 0x00 && $b[1] === 0x64 && $b[2] === 0xFF && $b[3] === 0x9B) {
            for ($i = 4; $i < 12; $i++) {
                if ($b[$i] !== 0) {
                    break;
                }
                if ($i === 11) {
                    return self::isForbiddenIpv4(substr($packed, 12, 4));
                }
            }
        }

        return $b[0] === 0xFF                                    // ff00::/8 multicast
            || ($b[0] === 0xFE && ($b[1] & 0xC0) === 0x80)       // fe80::/10 link-local
            || ($b[0] & 0xFE) === 0xFC                           // fc00::/7 unique local
            || ($b[0] === 0x20 && $b[1] === 0x01 && $b[2] === 0x0D && $b[3] === 0xB8); // 2001:db8::/32 documentation
    }

    /**
     * @return array<int, string>
     */
    private static function systemResolver(string $host): array
    {
        $addresses = [];

        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            $addresses = $v4;
        }

        try {
            $records = @dns_get_record($host, DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $record) {
                    if (!empty($record['ipv6'])) {
                        $addresses[] = $record['ipv6'];
                    }
                }
            }
        } catch (\Throwable) {
            // DNS failure — fall through; an empty list is rejected by the caller.
        }

        return array_values(array_unique($addresses));
    }

    private static function denied(): \InvalidArgumentException
    {
        return new \InvalidArgumentException(self::ERROR_MESSAGE);
    }
}
