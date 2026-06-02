<?php

namespace App\Service;

use App\Entity\Organization;
use App\Repository\MailerConfigRepository;
use App\Service\Storage\CredentialEncryptor;

class MailerConfigResolver
{
    public function __construct(
        private readonly MailerConfigRepository $repository,
        private readonly LicenseManager $licenseManager,
        private readonly CredentialEncryptor $credentialEncryptor,
    ) {}

    /**
     * Resolve the decrypted per-org SMTP sender, or null when the org is not
     * entitled (Business) or has no enabled, complete configuration.
     *
     * @return array{host:string,port:int,encryption:string,username:?string,password:?string,fromAddress:string,fromName:?string}|null
     */
    public function resolve(Organization $org): ?array
    {
        if (!$this->licenseManager->canUseWhiteLabel($org)) {
            return null;
        }

        $config = $this->repository->findByOrganization($org);
        if (!$config || !$config->isEnabled() || $config->getHost() === '' || $config->getFromAddress() === '') {
            return null;
        }

        $credentials = $config->getEncryptedCredentials() !== ''
            ? $this->credentialEncryptor->decrypt($config->getEncryptedCredentials())
            : [];

        return [
            'host' => $config->getHost(),
            'port' => $config->getPort(),
            'encryption' => $config->getEncryption(),
            'username' => $config->getUsername(),
            'password' => $credentials['password'] ?? null,
            'fromAddress' => $config->getFromAddress(),
            'fromName' => $config->getFromName(),
        ];
    }
}
