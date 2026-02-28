<?php

namespace App\Service;

use App\Entity\Company;
use App\Entity\Organization;
use App\Repository\CompanyRepository;
use Doctrine\ORM\EntityManagerInterface;

class CompanyReadOnlyService
{
    public function __construct(
        private readonly CompanyRepository $companyRepository,
        private readonly LicenseManager $licenseManager,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /**
     * Enforce company limits after a plan change.
     * If the org has more companies than allowed, mark extras as read-only.
     * If upgrading and count is within limit, unset read-only on all.
     */
    public function enforceCompanyLimits(Organization $org): void
    {
        $maxCompanies = $this->licenseManager->getFeatures($org)['maxCompanies'];
        $companies = $this->companyRepository->findBy(
            ['organization' => $org],
            ['updatedAt' => 'DESC']
        );

        $count = count($companies);

        if ($count <= $maxCompanies) {
            // Upgrade case: all companies become writable
            foreach ($companies as $company) {
                if ($company->isReadOnly()) {
                    $company->setReadOnly(false);
                }
            }
        } else {
            // Downgrade case: keep N most-recently-updated companies writable
            foreach ($companies as $i => $company) {
                $company->setReadOnly($i >= $maxCompanies);
            }
        }

        $this->entityManager->flush();
    }

    /**
     * Swap which company is active (writable).
     * Marks the chosen company as writable. If writable count would exceed
     * the limit, marks the oldest-updated writable company as read-only.
     */
    public function setActiveCompany(Organization $org, Company $company): void
    {
        $maxCompanies = $this->licenseManager->getFeatures($org)['maxCompanies'];

        // Already writable — nothing to do
        if (!$company->isReadOnly()) {
            return;
        }

        $company->setReadOnly(false);

        // Count currently writable companies (including the one we just made writable)
        $companies = $this->companyRepository->findBy(
            ['organization' => $org],
            ['updatedAt' => 'DESC']
        );

        $writable = array_filter($companies, fn(Company $c) => !$c->isReadOnly());
        $writableCount = count($writable);

        if ($writableCount > $maxCompanies) {
            // Find the oldest-updated writable company (excluding the one we just activated)
            // Sort writable by updatedAt ascending to find the oldest
            usort($writable, function (Company $a, Company $b) {
                $aDate = $a->getUpdatedAt() ?? $a->getCreatedAt();
                $bDate = $b->getUpdatedAt() ?? $b->getCreatedAt();
                return $aDate <=> $bDate;
            });

            foreach ($writable as $c) {
                if ($c->getId()->equals($company->getId())) {
                    continue;
                }
                $c->setReadOnly(true);
                break;
            }
        }

        $this->entityManager->flush();
    }
}
