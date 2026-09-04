<?php

namespace App\Tests\Unit;

use App\Entity\Organization;
use App\Repository\CompanyRepository;
use App\Repository\OrganizationMembershipRepository;
use App\Service\LicenseManager;
use App\Service\LicenseValidationService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * getEffectivePlan() must hand out a paid plan only for subscription states
 * that are actually paid for: active, trialing, past_due inside the dunning
 * grace window, or a plan set without any Stripe subscription. Every other
 * Stripe status falls back to the trial / Freemium.
 */
class LicenseManagerEffectivePlanTest extends TestCase
{
    private function makeManager(): LicenseManager
    {
        $validation = $this->createMock(LicenseValidationService::class);
        $validation->method('isSelfHosted')->willReturn(false);
        $validation->method('isJwtLicense')->willReturn(false);

        return new LicenseManager(
            $this->createMock(CompanyRepository::class),
            $this->createMock(OrganizationMembershipRepository::class),
            $validation,
            'sk_test_saas', // non-empty → SaaS mode, not Community Edition
        );
    }

    private function makeOrg(
        string $plan = LicenseManager::PLAN_PROFESSIONAL,
        ?string $status = null,
        ?string $subscriptionId = null,
        ?string $trialEndsAt = null,
        ?string $dunningFailedAt = null,
        ?string $updatedAt = null,
    ): Organization {
        $org = new Organization();
        $org->setName('Acme');
        $org->setSlug('acme');
        $org->setPlan($plan);
        $org->setSubscriptionStatus($status);
        $org->setStripeSubscriptionId($subscriptionId);

        if ($trialEndsAt !== null) {
            $org->setTrialEndsAt(new \DateTimeImmutable($trialEndsAt));
        }
        if ($dunningFailedAt !== null) {
            $org->setSettings(['dunning_failed_at' => (new \DateTimeImmutable($dunningFailedAt))->format('Y-m-d\TH:i:sP')]);
        }
        if ($updatedAt !== null) {
            $org->setUpdatedAt(new \DateTimeImmutable($updatedAt));
        }

        return $org;
    }

    public function testActiveSubscriptionGrantsStoredPlan(): void
    {
        $org = $this->makeOrg(status: 'active', subscriptionId: 'sub_1');

        self::assertSame(LicenseManager::PLAN_PROFESSIONAL, $this->makeManager()->getEffectivePlan($org));
    }

    public function testTrialingSubscriptionGrantsStoredPlan(): void
    {
        $org = $this->makeOrg(plan: LicenseManager::PLAN_BUSINESS, status: 'trialing', subscriptionId: 'sub_1');

        self::assertSame(LicenseManager::PLAN_BUSINESS, $this->makeManager()->getEffectivePlan($org));
    }

    public function testLegacyProPlanNameIsNormalized(): void
    {
        $org = $this->makeOrg(plan: 'pro', status: 'active', subscriptionId: 'sub_1');

        self::assertSame(LicenseManager::PLAN_PROFESSIONAL, $this->makeManager()->getEffectivePlan($org));
    }

    public function testPastDueInsideGraceKeepsPlan(): void
    {
        $org = $this->makeOrg(status: 'past_due', subscriptionId: 'sub_1', dunningFailedAt: '-3 days');

        self::assertSame(LicenseManager::PLAN_PROFESSIONAL, $this->makeManager()->getEffectivePlan($org));
    }

    public function testPastDueAfterGraceFallsBackToFreemium(): void
    {
        $org = $this->makeOrg(status: 'past_due', subscriptionId: 'sub_1', dunningFailedAt: '-20 days', updatedAt: '-1 hour');

        self::assertSame(LicenseManager::PLAN_FREEMIUM, $this->makeManager()->getEffectivePlan($org));
    }

    public function testPastDueGraceFallsBackToUpdatedAtWhenNoFailureTimestamp(): void
    {
        $recent = $this->makeOrg(status: 'past_due', subscriptionId: 'sub_1', updatedAt: '-5 days');
        $stale = $this->makeOrg(status: 'past_due', subscriptionId: 'sub_1', updatedAt: '-30 days');

        $manager = $this->makeManager();
        self::assertSame(LicenseManager::PLAN_PROFESSIONAL, $manager->getEffectivePlan($recent));
        self::assertSame(LicenseManager::PLAN_FREEMIUM, $manager->getEffectivePlan($stale));
    }

    public function testPastDueWithNoGraceAnchorFallsBackToFreemium(): void
    {
        $org = $this->makeOrg(status: 'past_due', subscriptionId: 'sub_1');

        self::assertSame(LicenseManager::PLAN_FREEMIUM, $this->makeManager()->getEffectivePlan($org));
    }

    #[DataProvider('nonGrantingStatuses')]
    public function testOtherStripeStatusesFallBackToFreemium(string $status): void
    {
        $org = $this->makeOrg(status: $status, subscriptionId: 'sub_1', updatedAt: '-1 minute');

        self::assertSame(LicenseManager::PLAN_FREEMIUM, $this->makeManager()->getEffectivePlan($org), $status);
    }

    public static function nonGrantingStatuses(): iterable
    {
        yield 'unpaid' => ['unpaid'];
        yield 'paused' => ['paused'];
        yield 'incomplete' => ['incomplete'];
        yield 'canceled' => ['canceled'];
        yield 'incomplete_expired' => ['incomplete_expired'];
    }

    public function testNonGrantingStatusStillHonoursActiveTrial(): void
    {
        $org = $this->makeOrg(status: 'incomplete', subscriptionId: 'sub_1', trialEndsAt: '+5 days');

        self::assertSame(LicenseManager::PLAN_STARTER, $this->makeManager()->getEffectivePlan($org));
    }

    public function testSubscriptionIdWithoutStatusDoesNotGrantPlan(): void
    {
        $org = $this->makeOrg(status: null, subscriptionId: 'sub_1');

        self::assertSame(LicenseManager::PLAN_FREEMIUM, $this->makeManager()->getEffectivePlan($org));
    }

    public function testManuallySetPlanWithoutStripeSubscriptionIsHonoured(): void
    {
        // Admin panel / app:plan:upgrade / self-hosted license sync: no Stripe fields at all
        $org = $this->makeOrg(plan: LicenseManager::PLAN_STARTER);

        self::assertSame(LicenseManager::PLAN_STARTER, $this->makeManager()->getEffectivePlan($org));
    }

    public function testActiveTrialGrantsStarter(): void
    {
        $org = $this->makeOrg(plan: LicenseManager::PLAN_FREEMIUM, trialEndsAt: '+7 days');

        self::assertSame(LicenseManager::PLAN_STARTER, $this->makeManager()->getEffectivePlan($org));
    }

    public function testExpiredTrialFallsBackToFreemiumNotExpired(): void
    {
        $org = $this->makeOrg(plan: LicenseManager::PLAN_FREEMIUM, trialEndsAt: '-1 day');

        $manager = $this->makeManager();
        self::assertSame(LicenseManager::PLAN_FREEMIUM, $manager->getEffectivePlan($org));
        self::assertFalse($manager->isExpired($org));
        self::assertTrue($manager->canAutoSync($org));
        self::assertFalse($manager->canUseBankStatements($org));
    }
}
