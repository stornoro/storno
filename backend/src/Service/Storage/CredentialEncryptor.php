<?php

namespace App\Service\Storage;

class CredentialEncryptor
{
    private readonly string $key;

    public function __construct(string $storageEncryptionKey)
    {
        $this->key = sodium_crypto_generichash($storageEncryptionKey, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    public function encrypt(array $credentials): string
    {
        $json = json_encode($credentials, JSON_THROW_ON_ERROR);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($json, $nonce, $this->key);

        return base64_encode($nonce . $ciphertext);
    }

    public function decrypt(string $encrypted): array
    {
        $decoded = base64_decode($encrypted, true);
        if ($decoded === false) {
            throw new \RuntimeException('Invalid encrypted credentials: base64 decode failed');
        }

        $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $json = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->key);
        if ($json === false) {
            throw new \RuntimeException('Failed to decrypt credentials: invalid key or corrupted data');
        }

        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }
}
