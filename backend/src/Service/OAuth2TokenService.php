<?php

namespace App\Service;

class OAuth2TokenService
{
    /**
     * @return string Client ID with storno_cid_ prefix
     */
    public function generateClientId(): string
    {
        return 'storno_cid_' . bin2hex(random_bytes(16));
    }

    /**
     * @return array{raw: string, hash: string, prefix: string}
     */
    public function generateClientSecret(): array
    {
        $raw = 'storno_cs_' . bin2hex(random_bytes(32));
        $hash = hash('sha256', $raw);
        $prefix = substr($raw, 0, 12);

        return ['raw' => $raw, 'hash' => $hash, 'prefix' => $prefix];
    }

    /**
     * @return array{raw: string, hash: string}
     */
    public function generateAuthorizationCode(): array
    {
        $raw = bin2hex(random_bytes(32));
        $hash = hash('sha256', $raw);

        return ['raw' => $raw, 'hash' => $hash];
    }

    /**
     * @return array{raw: string, hash: string, prefix: string}
     */
    public function generateAccessToken(): array
    {
        $raw = 'storno_oat_' . bin2hex(random_bytes(32));
        $hash = hash('sha256', $raw);
        $prefix = substr($raw, 0, 12);

        return ['raw' => $raw, 'hash' => $hash, 'prefix' => $prefix];
    }

    /**
     * @return array{raw: string, hash: string}
     */
    public function generateRefreshToken(): array
    {
        $raw = 'storno_ort_' . bin2hex(random_bytes(32));
        $hash = hash('sha256', $raw);

        return ['raw' => $raw, 'hash' => $hash];
    }

    public function hashToken(string $raw): string
    {
        return hash('sha256', $raw);
    }

    /**
     * Verify PKCE code_verifier against stored code_challenge (S256 method).
     */
    public function verifyPkce(string $codeVerifier, string $codeChallenge): bool
    {
        $computed = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        return hash_equals($codeChallenge, $computed);
    }

    /**
     * Generate a new family identifier for refresh token rotation chains.
     */
    public function generateFamily(): string
    {
        return bin2hex(random_bytes(16));
    }
}
