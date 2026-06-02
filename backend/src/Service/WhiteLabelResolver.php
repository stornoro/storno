<?php

namespace App\Service;

use App\Entity\Organization;
use App\Entity\WhiteLabelConfig;
use App\Repository\WhiteLabelConfigRepository;

class WhiteLabelResolver
{
    public function __construct(
        private readonly WhiteLabelConfigRepository $repository,
        private readonly LicenseManager $licenseManager,
    ) {}

    public function getConfig(Organization $org): ?WhiteLabelConfig
    {
        return $this->repository->findByOrganization($org);
    }

    /**
     * White-label is only in effect when the org is entitled (Business plan)
     * and has explicitly enabled it. A downgrade auto-disables it here.
     */
    public function isActive(Organization $org): bool
    {
        if (!$this->licenseManager->canUseWhiteLabel($org)) {
            return false;
        }

        $config = $this->getConfig($org);

        return $config !== null && $config->isEnabled();
    }

    /**
     * Resolved branding for API responses, or null when white-label is inactive.
     */
    public function resolve(Organization $org): ?array
    {
        if (!$this->isActive($org)) {
            return null;
        }

        $config = $this->getConfig($org);

        return [
            'appName' => $config->getAppName(),
            'logoUrl' => $config->getLogoPath() ? '/v1/white-label/logo' : null,
            'primaryColor' => $config->getPrimaryColor(),
            'removeBranding' => $config->isRemoveBranding(),
        ];
    }

    public function shouldHideBranding(Organization $org): bool
    {
        return $this->isActive($org) && (bool) $this->getConfig($org)?->isRemoveBranding();
    }
}
