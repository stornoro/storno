<?php

namespace App\Service;

final class EditionService
{
    public function __construct(
        private readonly LicenseValidationService $licenseValidationService,
    ) {}

    public function isSaas(): bool
    {
        return !$this->licenseValidationService->isSelfHosted();
    }
}
