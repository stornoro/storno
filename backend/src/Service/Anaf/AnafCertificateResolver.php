<?php

namespace App\Service\Anaf;

use App\Entity\AnafToken;
use App\Service\Storage\CredentialEncryptor;
use League\Flysystem\FilesystemOperator;

class AnafCertificateResolver
{
    public function __construct(
        private readonly FilesystemOperator $defaultStorage,
        private readonly CredentialEncryptor $credentialEncryptor,
    ) {}

    /**
     * Download the PFX certificate from storage to a temp file and decrypt the passphrase.
     *
     * @return array{certPath: string, passphrase: string}|null
     */
    public function resolve(AnafToken $anafToken): ?array
    {
        $storagePath = $anafToken->getCertificatePath();
        $encryptedPassword = $anafToken->getCertificatePassword();

        if ($storagePath === null || $encryptedPassword === null) {
            return null;
        }

        $certContents = $this->defaultStorage->read($storagePath);

        $tempPath = tempnam(sys_get_temp_dir(), 'anaf_cert_') . '.pfx';
        file_put_contents($tempPath, $certContents);

        $credentials = $this->credentialEncryptor->decrypt($encryptedPassword);
        $passphrase = $credentials['password'] ?? '';

        return [
            'certPath' => $tempPath,
            'passphrase' => $passphrase,
        ];
    }

    /**
     * Clean up a temporary certificate file after use.
     */
    public function cleanup(string $certPath): void
    {
        if (file_exists($certPath)) {
            @unlink($certPath);
        }
    }
}
