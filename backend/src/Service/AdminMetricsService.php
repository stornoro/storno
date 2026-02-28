<?php

namespace App\Service;

use App\Repository\OrganizationRepository;
use Doctrine\ORM\EntityManagerInterface;

class AdminMetricsService
{
    public function __construct(
        private readonly OrganizationRepository $organizationRepository,
        private readonly EntityManagerInterface $em,
        private readonly LicenseManager $licenseManager,
    ) {}

    /**
     * Monthly Recurring Revenue in bani (1/100 RON).
     * Sums monthly plan prices for all orgs with an active or trialing subscription.
     */
    public function getMRR(): int
    {
        $pricing = LicenseManager::getPlanPricing();

        $rows = $this->em->createQuery(
            "SELECT o.plan, COUNT(o.id) AS cnt
             FROM App\Entity\Organization o
             WHERE o.subscriptionStatus IN ('active', 'trialing')
             AND o.plan IN (:plans)
             GROUP BY o.plan"
        )
            ->setParameter('plans', LicenseManager::ALL_PLANS)
            ->getResult();

        $mrr = 0;
        foreach ($rows as $row) {
            $mrr += ((int) $row['cnt']) * ($pricing[$row['plan']]['monthlyPrice'] ?? 0);
        }

        return $mrr;
    }

    /**
     * Annual Recurring Revenue — MRR * 12.
     */
    public function getARR(): int
    {
        return $this->getMRR() * 12;
    }

    /**
     * Churn rate as a percentage over the given number of trailing months.
     * Formula: (subscriptions canceled in period / subscriptions active at start of period) * 100
     */
    public function getChurnRate(int $months = 1): float
    {
        $since = new \DateTimeImmutable(sprintf('-%d months', $months));

        // Organizations whose subscription was canceled in the period
        $canceled = (int) $this->em->createQuery(
            "SELECT COUNT(o.id)
             FROM App\Entity\Organization o
             WHERE o.subscriptionStatus = 'canceled'
             AND o.updatedAt >= :since"
        )
            ->setParameter('since', $since)
            ->getSingleScalarResult();

        // Organizations that had an active subscription at the start of the period
        $activeAtStart = (int) $this->em->createQuery(
            "SELECT COUNT(o.id)
             FROM App\Entity\Organization o
             WHERE o.subscriptionStatus IN ('active', 'trialing', 'canceled')
             AND o.createdAt <= :since"
        )
            ->setParameter('since', $since)
            ->getSingleScalarResult();

        if ($activeAtStart === 0) {
            return 0.0;
        }

        return round(($canceled / $activeAtStart) * 100, 2);
    }

    /**
     * Trial-to-paid conversion rate as a percentage.
     * Formula: (orgs with expired trial that have an active subscription / orgs with any expired trial) * 100
     */
    public function getTrialConversionRate(): float
    {
        $now = new \DateTimeImmutable();

        // Orgs whose trial has ended (trial was set and has expired)
        $expiredTrials = (int) $this->em->createQuery(
            'SELECT COUNT(o.id)
             FROM App\Entity\Organization o
             WHERE o.trialEndsAt IS NOT NULL
             AND o.trialEndsAt < :now'
        )
            ->setParameter('now', $now)
            ->getSingleScalarResult();

        if ($expiredTrials === 0) {
            return 0.0;
        }

        // Of those, how many converted to a paid subscription
        $converted = (int) $this->em->createQuery(
            "SELECT COUNT(o.id)
             FROM App\Entity\Organization o
             WHERE o.trialEndsAt IS NOT NULL
             AND o.trialEndsAt < :now
             AND o.subscriptionStatus IN ('active', 'trialing')
             AND o.stripeSubscriptionId IS NOT NULL"
        )
            ->setParameter('now', $now)
            ->getSingleScalarResult();

        return round(($converted / $expiredTrials) * 100, 2);
    }

    /**
     * Count of active subscriptions grouped by plan.
     * Returns an associative array: ['starter' => 5, 'professional' => 3, ...]
     */
    public function getSubscriptionsByPlan(): array
    {
        $rows = $this->em->createQuery(
            "SELECT o.plan, COUNT(o.id) AS cnt
             FROM App\Entity\Organization o
             WHERE o.subscriptionStatus IN ('active', 'trialing')
             GROUP BY o.plan"
        )->getResult();

        $result = [];
        foreach ($rows as $row) {
            $result[$row['plan']] = (int) $row['cnt'];
        }

        return $result;
    }

    /**
     * Count of organizations whose trial ends within the next 7 days.
     */
    public function getTrialsExpiringThisWeek(): int
    {
        $now = new \DateTimeImmutable();
        $inSevenDays = $now->modify('+7 days');

        return (int) $this->em->createQuery(
            'SELECT COUNT(o.id)
             FROM App\Entity\Organization o
             WHERE o.trialEndsAt IS NOT NULL
             AND o.trialEndsAt >= :now
             AND o.trialEndsAt <= :inSevenDays'
        )
            ->setParameter('now', $now)
            ->setParameter('inSevenDays', $inSevenDays)
            ->getSingleScalarResult();
    }

    /**
     * Monthly revenue grouped by plan, in bani.
     * Returns an associative array: ['starter' => 19000, 'professional' => 11700, ...]
     */
    public function getRevenueByPlan(): array
    {
        $pricing = LicenseManager::getPlanPricing();

        $rows = $this->em->createQuery(
            "SELECT o.plan, COUNT(o.id) AS cnt
             FROM App\Entity\Organization o
             WHERE o.subscriptionStatus IN ('active', 'trialing')
             AND o.plan IN (:plans)
             GROUP BY o.plan"
        )
            ->setParameter('plans', LicenseManager::ALL_PLANS)
            ->getResult();

        $result = [];
        foreach ($rows as $row) {
            $plan = $row['plan'];
            $count = (int) $row['cnt'];
            $result[$plan] = $count * ($pricing[$plan]['monthlyPrice'] ?? 0);
        }

        return $result;
    }

    /**
     * Total number of organizations.
     */
    public function getTotalOrganizations(): int
    {
        return (int) $this->em->createQuery(
            'SELECT COUNT(o.id) FROM App\Entity\Organization o'
        )->getSingleScalarResult();
    }

    /**
     * Total number of active users.
     */
    public function getTotalUsers(): int
    {
        return (int) $this->em->createQuery(
            'SELECT COUNT(u.id) FROM App\Entity\User u WHERE u.active = true'
        )->getSingleScalarResult();
    }

    /**
     * Total number of companies.
     */
    public function getTotalCompanies(): int
    {
        return (int) $this->em->createQuery(
            'SELECT COUNT(c.id) FROM App\Entity\Company c'
        )->getSingleScalarResult();
    }

    /**
     * Compile all metrics into a single response array.
     */
    public function getAllMetrics(): array
    {
        $mrr = $this->getMRR();

        return [
            'mrr' => $mrr,
            'arr' => $mrr * 12,
            'currency' => 'RON',
            'churnRate' => $this->getChurnRate(),
            'trialConversionRate' => $this->getTrialConversionRate(),
            'subscriptionsByPlan' => $this->getSubscriptionsByPlan(),
            'trialsExpiringThisWeek' => $this->getTrialsExpiringThisWeek(),
            'revenueByPlan' => $this->getRevenueByPlan(),
            'totalOrganizations' => $this->getTotalOrganizations(),
            'totalUsers' => $this->getTotalUsers(),
            'totalCompanies' => $this->getTotalCompanies(),
        ];
    }
}
