<?php

namespace App\Service;

use App\Entity\Organization;
use App\Repository\WhiteLabelConfigRepository;

/**
 * Resolves the public frontend base URL for an organization — its verified
 * custom domain when entitled (Business) and enabled, otherwise the platform
 * default (FRONTEND_URL). Used to build client-facing links (share, payment).
 */
class FrontendUrlResolver
{
    public function __construct(
        private readonly WhiteLabelConfigRepository $repository,
        private readonly LicenseManager $licenseManager,
        private readonly string $frontendUrl,
    ) {}

    public function resolve(?Organization $org): string
    {
        if ($org && $this->licenseManager->canUseWhiteLabel($org)) {
            $config = $this->repository->findByOrganization($org);
            if ($config && $config->isEnabled() && $config->getCustomDomain() && $config->isCustomDomainVerified()) {
                return 'https://' . $config->getCustomDomain();
            }
        }

        return rtrim($this->frontendUrl, '/');
    }
}
