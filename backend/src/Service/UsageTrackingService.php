<?php

namespace App\Service;

use App\Entity\Organization;
use App\Repository\CompanyRepository;
use App\Repository\InvoiceRepository;
use App\Repository\OrganizationMembershipRepository;

class UsageTrackingService
{
    public function __construct(
        private readonly InvoiceRepository $invoiceRepository,
        private readonly CompanyRepository $companyRepository,
        private readonly OrganizationMembershipRepository $membershipRepository,
        private readonly LicenseManager $licenseManager,
    ) {}

    /**
     * Count invoices created this month across all companies belonging to the organization.
     */
    public function getMonthlyInvoiceCount(Organization $org): int
    {
        $firstDayOfMonth = new \DateTimeImmutable('first day of this month 00:00:00');

        return (int) $this->invoiceRepository->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->join('i.company', 'c')
            ->where('c.organization = :org')
            ->andWhere('i.createdAt >= :since')
            ->andWhere('i.deletedAt IS NULL')
            ->setParameter('org', $org)
            ->setParameter('since', $firstDayOfMonth)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Return usage metrics for invoices, companies, and users.
     *
     * Each entry contains:
     *   - used       : current consumption
     *   - limit      : plan limit (0 = unlimited)
     *   - percentage : 0-100, or null when unlimited
     */
    public function getUsagePercentage(Organization $org): array
    {
        $features = $this->licenseManager->getFeatures($org);

        // Invoices
        $invoiceLimit = $features['maxInvoicesPerMonth'];
        $invoiceUsed = $this->getMonthlyInvoiceCount($org);
        $invoicePercentage = $invoiceLimit > 0
            ? min(100, (int) round($invoiceUsed / $invoiceLimit * 100))
            : null;

        // Companies
        $companyLimit = $features['maxCompanies'];
        $companyUsed = $this->companyRepository->count(['organization' => $org]);
        $companyPercentage = $companyLimit > 0 && $companyLimit < 999999
            ? min(100, (int) round($companyUsed / $companyLimit * 100))
            : null;

        // Users (active memberships)
        $userLimit = $features['maxUsersPerOrg'];
        $userUsed = $this->membershipRepository->count(['organization' => $org, 'isActive' => true]);
        $userPercentage = $userLimit > 0 && $userLimit < 999999
            ? min(100, (int) round($userUsed / $userLimit * 100))
            : null;

        return [
            'invoices' => [
                'used' => $invoiceUsed,
                'limit' => $invoiceLimit,
                'percentage' => $invoicePercentage,
            ],
            'companies' => [
                'used' => $companyUsed,
                'limit' => $companyLimit < 999999 ? $companyLimit : 0,
                'percentage' => $companyPercentage,
            ],
            'users' => [
                'used' => $userUsed,
                'limit' => $userLimit < 999999 ? $userLimit : 0,
                'percentage' => $userPercentage,
            ],
        ];
    }
}
