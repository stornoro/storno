<?php

namespace App\Service;

use App\Entity\Organization;
use App\Repository\CompanyRepository;
use App\Repository\OrganizationMembershipRepository;

class LicenseManager
{
    public function __construct(
        private readonly CompanyRepository $companyRepository,
        private readonly OrganizationMembershipRepository $membershipRepository,
        private readonly LicenseValidationService $licenseValidation,
        private readonly string $stripeSecretKey,
    ) {}

    public const PLAN_COMMUNITY = 'community';
    public const PLAN_FREEMIUM = 'freemium';
    public const PLAN_STARTER = 'starter';
    public const PLAN_PROFESSIONAL = 'professional';
    public const PLAN_BUSINESS = 'business';

    /**
     * Internal state for expired trials / canceled subscriptions.
     * Not a selectable plan — uses 'free' DB value for backward compatibility.
     */
    public const PLAN_EXPIRED = 'free';

    public const ALL_PLANS = [self::PLAN_STARTER, self::PLAN_PROFESSIONAL, self::PLAN_BUSINESS];

    private const PLAN_LIMITS = [
        self::PLAN_FREEMIUM => [
            'maxCompanies' => 1,
            'maxUsersPerOrg' => 3,
            'autoSync' => true,
            'syncIntervalSeconds' => 86400, // 24h — 1 sync/day
            'maxInvoicesPerMonth' => 100,
            'mobileApp' => false,
            'pdfGeneration' => true,
            'signatureVerification' => true,
            'apiAccess' => true,
            'realtimeNotifications' => false,
            'paymentLinks' => false,
            'emailSending' => true,
            'emailTemplates' => true,
            'reports' => true,
            'recurringInvoices' => false,
            'importExport' => false,
            'backupRestore' => false,
            'bankStatements' => false,
            'exchangeRates' => true,
            'webhooks' => false,
            'selfHostingLicense' => false,
            'prioritySupport' => false,
        ],
        self::PLAN_EXPIRED => [
            'maxCompanies' => 1,
            'maxUsersPerOrg' => 1,
            'autoSync' => false,
            'syncIntervalSeconds' => 0,
            'maxInvoicesPerMonth' => 0,
            'mobileApp' => false,
            'pdfGeneration' => false,
            'signatureVerification' => true,
            'apiAccess' => true,
            'realtimeNotifications' => false,
            'paymentLinks' => false,
            'emailSending' => false,
            'emailTemplates' => false,
            'reports' => false,
            'recurringInvoices' => false,
            'importExport' => false,
            'backupRestore' => false,
            'bankStatements' => false,
            'exchangeRates' => true,
            'webhooks' => false,
            'selfHostingLicense' => false,
            'prioritySupport' => false,
        ],
        self::PLAN_STARTER => [
            'maxCompanies' => 3,
            'maxUsersPerOrg' => 3,
            'autoSync' => true,
            'syncIntervalSeconds' => 43200, // 12h — 2 syncs/day
            'maxInvoicesPerMonth' => 500,
            'mobileApp' => true,
            'pdfGeneration' => true,
            'signatureVerification' => true,
            'apiAccess' => true,
            'realtimeNotifications' => false,
            'paymentLinks' => true,
            'emailSending' => true,
            'emailTemplates' => true,
            'reports' => true,
            'recurringInvoices' => false,
            'importExport' => true,
            'backupRestore' => false,
            'bankStatements' => false,
            'exchangeRates' => true,
            'webhooks' => false,
            'selfHostingLicense' => false,
            'prioritySupport' => false,
        ],
        self::PLAN_PROFESSIONAL => [
            'maxCompanies' => 10,
            'maxUsersPerOrg' => 10,
            'autoSync' => true,
            'syncIntervalSeconds' => 14400, // 4h — 6 syncs/day
            'maxInvoicesPerMonth' => 0, // unlimited
            'mobileApp' => true,
            'pdfGeneration' => true,
            'signatureVerification' => true,
            'apiAccess' => true,
            'realtimeNotifications' => false,
            'paymentLinks' => true,
            'emailSending' => true,
            'emailTemplates' => true,
            'reports' => true,
            'recurringInvoices' => true,
            'importExport' => true,
            'backupRestore' => true,
            'bankStatements' => true,
            'exchangeRates' => true,
            'webhooks' => true,
            'selfHostingLicense' => false,
            'prioritySupport' => false,
        ],
        self::PLAN_BUSINESS => [
            'maxCompanies' => 999999,
            'maxUsersPerOrg' => 999999,
            'autoSync' => true,
            'syncIntervalSeconds' => 3600, // 1h — 24 syncs/day (ANAF limits still apply)
            'maxInvoicesPerMonth' => 0, // unlimited
            'mobileApp' => true,
            'pdfGeneration' => true,
            'signatureVerification' => true,
            'apiAccess' => true,
            'realtimeNotifications' => true,
            'paymentLinks' => true,
            'emailSending' => true,
            'emailTemplates' => true,
            'reports' => true,
            'recurringInvoices' => true,
            'importExport' => true,
            'backupRestore' => true,
            'bankStatements' => true,
            'exchangeRates' => true,
            'webhooks' => true,
            'selfHostingLicense' => true,
            'prioritySupport' => true,
        ],
    ];

    private const TRIAL_DAYS = 14;

    /**
     * Resolve the effective plan for an organization.
     * Active subscriptions and trials return a real plan; otherwise expired.
     */
    public function getEffectivePlan(Organization $org): string
    {
        // Community Edition: no Stripe (not SaaS), no license key → Starter features
        if ($this->isCommunityEdition()) {
            return self::PLAN_STARTER;
        }

        // Self-hosted with JWT license → use plan from JWT claims
        if ($this->licenseValidation->isJwtLicense()) {
            $jwt = $this->licenseValidation->validateJwtLicense();
            if ($jwt && !($jwt['_expired'] ?? false)) {
                $jwtPlan = $jwt['plan'] ?? self::PLAN_STARTER;
                if (\in_array($jwtPlan, self::ALL_PLANS, true)) {
                    return $jwtPlan;
                }
            }
            // Expired or invalid JWT → community fallback
            return self::PLAN_STARTER;
        }

        $plan = $org->getPlan();

        // Normalize legacy plan names
        if ($plan === 'pro') {
            $plan = self::PLAN_PROFESSIONAL;
        }

        // Active Stripe subscription → use the org plan directly
        if ($org->hasActiveSubscription()) {
            if (\in_array($plan, self::ALL_PLANS, true)) {
                return $plan;
            }
        }

        // Past-due subscription: keep features active briefly (grace period)
        if ($org->getSubscriptionStatus() === 'past_due') {
            if (\in_array($plan, self::ALL_PLANS, true)) {
                return $plan;
            }
        }

        // Manually set plan (e.g. by admin) — only if subscription is not canceled
        if (\in_array($plan, self::ALL_PLANS, true)
            && !\in_array($org->getSubscriptionStatus(), ['canceled', 'incomplete_expired'], true)) {
            return $plan;
        }

        // Active trial → Starter features
        if ($org->isTrialActive()) {
            return self::PLAN_STARTER;
        }

        return self::PLAN_FREEMIUM;
    }

    /**
     * Is this a Community Edition instance? (no Stripe, no license key)
     */
    public function isCommunityEdition(): bool
    {
        return empty($this->stripeSecretKey) && !$this->licenseValidation->isSelfHosted();
    }

    /**
     * Check if the organization's plan/trial has expired with no active subscription.
     */
    public function isExpired(Organization $org): bool
    {
        return $this->getEffectivePlan($org) === self::PLAN_EXPIRED;
    }

    /**
     * Get feature limits for the organization's effective plan.
     */
    public function getFeatures(Organization $org): array
    {
        $plan = $this->getEffectivePlan($org);

        return self::PLAN_LIMITS[$plan] ?? self::PLAN_LIMITS[self::PLAN_EXPIRED];
    }

    public function canAutoSync(Organization $org): bool
    {
        return $this->getFeatures($org)['autoSync'];
    }

    public function getSyncInterval(Organization $org): int
    {
        return $this->getFeatures($org)['syncIntervalSeconds'];
    }

    public function canReceiveRealtimeNotifications(Organization $org): bool
    {
        return $this->getFeatures($org)['realtimeNotifications'];
    }

    public function canGeneratePdf(Organization $org): bool
    {
        return $this->getFeatures($org)['pdfGeneration'];
    }

    public function canVerifySignature(Organization $org): bool
    {
        return $this->getFeatures($org)['signatureVerification'];
    }

    public function canUsePaymentLinks(Organization $org): bool
    {
        return $this->getFeatures($org)['paymentLinks'];
    }

    public function canSendEmails(Organization $org): bool
    {
        return $this->getFeatures($org)['emailSending'];
    }

    public function canViewReports(Organization $org): bool
    {
        return $this->getFeatures($org)['reports'];
    }

    public function canUseRecurringInvoices(Organization $org): bool
    {
        return $this->getFeatures($org)['recurringInvoices'];
    }

    public function canImportExport(Organization $org): bool
    {
        return $this->getFeatures($org)['importExport'];
    }

    public function canBackupRestore(Organization $org): bool
    {
        return $this->getFeatures($org)['backupRestore'];
    }

    public function canUseBankStatements(Organization $org): bool
    {
        return $this->getFeatures($org)['bankStatements'];
    }

    public function canUseWebhooks(Organization $org): bool
    {
        return $this->getFeatures($org)['webhooks'];
    }

    public function canUseMobileApp(Organization $org): bool
    {
        return $this->getFeatures($org)['mobileApp'];
    }

    public function canUseEmailTemplates(Organization $org): bool
    {
        return $this->getFeatures($org)['emailTemplates'];
    }

    public function canUseExchangeRates(Organization $org): bool
    {
        return $this->getFeatures($org)['exchangeRates'];
    }

    public function getMaxInvoicesPerMonth(Organization $org): int
    {
        return $this->getFeatures($org)['maxInvoicesPerMonth'];
    }

    public function getMaxCompanies(Organization $org): int
    {
        return $this->getFeatures($org)['maxCompanies'];
    }

    public function getMaxUsers(Organization $org): int
    {
        return $this->getFeatures($org)['maxUsersPerOrg'];
    }

    /**
     * Can the org add another company?
     */
    public function canAddCompany(Organization $org): bool
    {
        $count = $this->companyRepository->count(['organization' => $org]);

        return $count < $this->getMaxCompanies($org);
    }

    /**
     * Can the org add another member?
     */
    public function canAddMember(Organization $org): bool
    {
        $count = $this->membershipRepository->count(['organization' => $org, 'isActive' => true]);

        return $count < $this->getMaxUsers($org);
    }

    /**
     * Start a 14-day free trial for a new organization.
     * Trial grants Starter-level features.
     */
    public function startTrial(Organization $org): void
    {
        if ($org->getTrialEndsAt() !== null) {
            return; // already had a trial
        }

        $org->setTrialEndsAt(new \DateTimeImmutable(sprintf('+%d days', self::TRIAL_DAYS)));
    }

    /**
     * Get plan status info for API responses.
     */
    public function getPlanStatus(Organization $org): array
    {
        $plan = $this->getEffectivePlan($org);

        $status = [
            'plan' => $plan,
            'expired' => $plan === self::PLAN_EXPIRED,
            'features' => $this->getFeatures($org),
        ];

        if ($org->getTrialEndsAt() !== null) {
            $status['trialEndsAt'] = $org->getTrialEndsAt()->format('c');
            $status['trialActive'] = $org->isTrialActive();

            if ($org->isTrialActive()) {
                $daysLeft = (int) (new \DateTimeImmutable())->diff($org->getTrialEndsAt())->days;
                $status['trialDaysLeft'] = $daysLeft;
            }
        }

        $isCommunity = $this->isCommunityEdition();
        $status['selfHosted'] = empty($this->stripeSecretKey) || $isCommunity;
        $status['communityEdition'] = $isCommunity;

        return $status;
    }

    /**
     * Get the pricing table for plans (used by billing API and public plans endpoint).
     */
    public static function getPlanPricing(): array
    {
        return [
            self::PLAN_COMMUNITY => [
                'monthlyPrice' => 0,
                'yearlyPrice' => 0,
                'currency' => 'RON',
                'selfHostedOnly' => true,
            ],
            self::PLAN_FREEMIUM => [
                'monthlyPrice' => 0,
                'yearlyPrice' => 0,
                'currency' => 'RON',
            ],
            self::PLAN_STARTER => [
                'monthlyPrice' => 1900, // 19 RON in bani
                'yearlyPrice' => 19000, // 190 RON in bani
                'currency' => 'RON',
                'trialDays' => self::TRIAL_DAYS,
                'savingsPercent' => (int) round((1 - 19000 / (1900 * 12)) * 100),
                'includedSeats' => 3,
                'extraSeatMonthlyPrice' => 500,  // 5 RON in bani
                'extraSeatYearlyPrice' => 5000,  // 50 RON in bani
            ],
            self::PLAN_PROFESSIONAL => [
                'monthlyPrice' => 3900, // 39 RON
                'yearlyPrice' => 39000, // 390 RON
                'currency' => 'RON',
                'savingsPercent' => (int) round((1 - 39000 / (3900 * 12)) * 100),
                'includedSeats' => 10,
                'extraSeatMonthlyPrice' => 900,  // 9 RON in bani
                'extraSeatYearlyPrice' => 9000,  // 90 RON in bani
            ],
            self::PLAN_BUSINESS => [
                'monthlyPrice' => 6900, // 69 RON
                'yearlyPrice' => 69000, // 690 RON
                'currency' => 'RON',
                'savingsPercent' => (int) round((1 - 69000 / (6900 * 12)) * 100),
                'includedSeats' => 10,
                'extraSeatMonthlyPrice' => 1500, // 15 RON in bani
                'extraSeatYearlyPrice' => 15000, // 150 RON in bani
            ],
        ];
    }

    /**
     * Get the features comparison table for all plans.
     */
    public static function getPlansComparison(): array
    {
        return self::PLAN_LIMITS;
    }

    /**
     * Get additive display features for each plan.
     *
     * Each plan only lists features NEW to that tier (not repeated from lower tiers).
     * The `includesPlan` key indicates which lower plan is fully included.
     * Features are ordered by business importance (most important first).
     */
    public static function getPlanDisplayFeatures(): array
    {
        return [
            self::PLAN_FREEMIUM => [
                'includesPlan' => null,
                'features' => [
                    'plan.feature.efacturaSync24h',
                    'plan.feature.pdfGeneration',
                    'plan.feature.emailSending',
                    'plan.feature.emailTemplates',
                    'plan.feature.reports',
                    'plan.feature.exchangeRates',
                    'plan.feature.maxCompanies1',
                    'plan.feature.maxUsers3',
                    'plan.feature.maxInvoices100',
                    'plan.feature.noTimeLimit',
                ],
            ],
            self::PLAN_STARTER => [
                'includesPlan' => self::PLAN_FREEMIUM,
                'features' => [
                    'plan.feature.efacturaSync12h',
                    'plan.feature.paymentLinks',
                    'plan.feature.mobileApp',
                    'plan.feature.importExport',
                    'plan.feature.maxCompanies3',
                    'plan.feature.maxInvoices500',
                ],
            ],
            self::PLAN_PROFESSIONAL => [
                'includesPlan' => self::PLAN_STARTER,
                'features' => [
                    'plan.feature.efacturaSync4h',
                    'plan.feature.unlimitedInvoices',
                    'plan.feature.recurringInvoices',
                    'plan.feature.backupRestore',
                    'plan.feature.bankStatements',
                    'plan.feature.webhooks',
                    'plan.feature.maxCompanies10',
                    'plan.feature.maxUsers10',
                ],
            ],
            self::PLAN_BUSINESS => [
                'includesPlan' => self::PLAN_PROFESSIONAL,
                'features' => [
                    'plan.feature.unlimitedCompaniesUsers',
                    'plan.feature.efacturaSync1h',
                    'plan.feature.realtimeNotifications',
                    'plan.feature.selfHostingLicense',
                    'plan.feature.prioritySupport',
                ],
            ],
        ];
    }
}
