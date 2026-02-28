<?php

namespace App\Service;

class ApiTokenService
{
    /**
     * Generate a new API token with raw value, hash, and prefix.
     *
     * @return array{raw: string, hash: string, prefix: string}
     */
    public function generateToken(): array
    {
        $random = bin2hex(random_bytes(32));
        $raw = 'af_' . $random;
        $hash = hash('sha256', $raw);
        $prefix = substr($raw, 0, 12);

        return ['raw' => $raw, 'hash' => $hash, 'prefix' => $prefix];
    }

    public function hashToken(string $raw): string
    {
        return hash('sha256', $raw);
    }
}
