<?php

namespace App\Service;

/**
 * Offline JWT license decoder.
 *
 * Verifies RS256-signed license tokens using an embedded RSA public key.
 * No network calls, no external dependencies — pure PHP openssl.
 *
 * JWT claims format:
 * {
 *   "iss": "<configured issuer>",
 *   "sub": "org-uuid",
 *   "plan": "business",
 *   "features": { ...feature map... },
 *   "orgName": "Acme Corp",
 *   "exp": 1735689600,
 *   "iat": 1704067200
 * }
 */
class JwtLicenseDecoder
{
    public function __construct(
        private readonly string $jwtIssuer = 'storno.ro',
    ) {}

    /**
     * RSA public key used to verify license JWT signatures.
     * Matches the private key held on the Storno.ro SaaS server.
     */
    private const PUBLIC_KEY = <<<'PEM'
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAqhcRLD3KjaWxfIAHjCBh
uH6jcRDpfMCybBxpp61b4G10eziuJ9meu5rle6VY4nwFPa0dUKd1owiwnzTuFwCW
EPbuCRjSoXC9tx7JvF6Gfc4sxKmvSIBRXkD5LjbgkDzjMJp8AMXHGM92qd3a+9uq
/WlghH7bK+IhusuvCCpJwh+08c5UFcAkFDNJFJBEiQyhLn1VGOOqu1t2bErjLUpz
7fGg1JuDIq+bBiqnJPQc5U6bO+i/HoQ2MwC9NkRDmOjq4+JA/e5sHyrfyUS3XMjU
tZijD7TdZGc01IK7D4nqwo7L6Hqxn3PLJJJxE2yiAx9LvqU6nIUPr9g01RXYkcvC
OwIDAQAB
-----END PUBLIC KEY-----
PEM;

    /**
     * Does the given license key look like a JWT? (base64url.base64url.base64url)
     */
    public function isJwtLicense(string $key): bool
    {
        return str_starts_with($key, 'eyJ') && substr_count($key, '.') === 2;
    }

    /**
     * Decode and verify a JWT license token.
     *
     * Returns the claims array on success, with `_expired: true` appended if past expiry.
     * Returns null if the signature is invalid or the token is malformed.
     */
    public function decode(string $token): ?array
    {
        $parts = explode('.', $token);
        if (\count($parts) !== 3) {
            return null;
        }

        [$headerB64, $payloadB64, $signatureB64] = $parts;

        // Verify RS256 signature
        $data = $headerB64 . '.' . $payloadB64;
        $signature = $this->base64UrlDecode($signatureB64);
        if ($signature === false) {
            return null;
        }

        $publicKey = openssl_pkey_get_public(self::PUBLIC_KEY);
        if (!$publicKey) {
            return null;
        }

        $valid = openssl_verify($data, $signature, $publicKey, \OPENSSL_ALGO_SHA256);
        if ($valid !== 1) {
            return null;
        }

        // Decode header — must be RS256
        $header = json_decode($this->base64UrlDecode($headerB64), true);
        if (!$header || ($header['alg'] ?? '') !== 'RS256') {
            return null;
        }

        // Decode payload
        $claims = json_decode($this->base64UrlDecode($payloadB64), true);
        if (!$claims) {
            return null;
        }

        // Check issuer
        if (($claims['iss'] ?? '') !== $this->jwtIssuer) {
            return null;
        }

        // Check expiry — don't reject, just flag
        if (isset($claims['exp']) && $claims['exp'] < time()) {
            $claims['_expired'] = true;
        }

        return $claims;
    }

    private function base64UrlDecode(string $input): string|false
    {
        $remainder = \strlen($input) % 4;
        if ($remainder) {
            $input .= str_repeat('=', 4 - $remainder);
        }

        return base64_decode(strtr($input, '-_', '+/'), true);
    }
}
