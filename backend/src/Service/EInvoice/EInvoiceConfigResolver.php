<?php

namespace App\Service\EInvoice;

use App\Entity\Company;
use App\Enum\EInvoiceProvider;
use App\Repository\CompanyEInvoiceConfigRepository;
use App\Service\Storage\CredentialEncryptor;

class EInvoiceConfigResolver
{
    public function __construct(
        private readonly CompanyEInvoiceConfigRepository $configRepository,
        private readonly CredentialEncryptor $encryptor,
    ) {}

    public function resolve(Company $company, EInvoiceProvider $provider): array
    {
        $config = $this->configRepository->findByCompanyAndProvider($company, $provider);

        if (!$config) {
            return [];
        }

        if ($config->getEncryptedConfig()) {
            return $this->encryptor->decrypt($config->getEncryptedConfig());
        }

        // Legacy plain-text fallback
        return $config->getConfig() ?? [];
    }
}
