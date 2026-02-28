<?php

namespace App\Controller\Api\V1;

use App\Security\OrganizationContext;
use App\Service\LicenseManager;
use App\Service\LicenseValidationService;
use App\Service\StripeService;
use App\Service\UsageTrackingService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/billing')]
class BillingController extends AbstractController
{
    public function __construct(
        private readonly StripeService $stripeService,
        private readonly LicenseManager $licenseManager,
        private readonly LicenseValidationService $licenseValidationService,
        private readonly OrganizationContext $organizationContext,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly UsageTrackingService $usageTrackingService,
        private readonly string $stripePublishableKey,
    ) {}

    /**
     * Return available plans with pricing info.
     *
     * SaaS mode: fetches from Stripe.
     * Self-hosted mode: returns deployment info + redirect URL.
     */
    #[Route('/plans', name: 'billing_plans', methods: ['GET'])]
    public function plans(): JsonResponse
    {
        $org = $this->organizationContext->getOrganization();
        if (!$org) {
            return $this->json(['error' => 'Organization not found'], Response::HTTP_NOT_FOUND);
        }

        // Self-hosted mode: no local Stripe, plans managed on SaaS
        if ($this->licenseValidationService->isSelfHosted()) {
            return $this->json([
                'plans' => [],
                'publishableKey' => '',
                'configured' => false,
                'selfHosted' => true,
                'billingUrl' => $this->licenseValidationService->getBillingUrl(),
            ]);
        }

        // SaaS mode: fetch from Stripe
        if (empty($this->stripePublishableKey)) {
            return $this->json([
                'plans' => [],
                'publishableKey' => '',
                'configured' => false,
            ]);
        }

        try {
            $prices = \Stripe\Price::all([
                'active' => true,
                'type' => 'recurring',
                'expand' => ['data.product'],
                'limit' => 20,
            ]);

            $displayFeatures = LicenseManager::getPlanDisplayFeatures();

            $plans = [];
            foreach ($prices->data as $price) {
                $product = $price->product;
                if (!$product || !$product->active) {
                    continue;
                }

                $planKey = $product->metadata['plan'] ?? null;
                if (!$planKey) {
                    continue;
                }

                // Only show plans available for subscription
                if (!\in_array($planKey, LicenseManager::ALL_PLANS, true)) {
                    continue;
                }

                $planDisplay = $displayFeatures[$planKey] ?? null;

                $plans[] = [
                    'priceId' => $price->id,
                    'plan' => $planKey,
                    'name' => $product->name,
                    'description' => $product->description,
                    'amount' => $price->unit_amount,
                    'currency' => $price->currency,
                    'interval' => $price->recurring->interval,
                    'intervalCount' => $price->recurring->interval_count,
                    'features' => $planDisplay ? $planDisplay['features'] : [],
                    'includesPlan' => $planDisplay ? $planDisplay['includesPlan'] : null,
                ];
            }

            $planOrder = [LicenseManager::PLAN_STARTER => 0, LicenseManager::PLAN_PROFESSIONAL => 1, LicenseManager::PLAN_BUSINESS => 2];
            usort($plans, fn ($a, $b) => ($planOrder[$a['plan']] ?? 99) <=> ($planOrder[$b['plan']] ?? 99));

            return $this->json([
                'plans' => $plans,
                'publishableKey' => $this->stripePublishableKey,
                'configured' => true,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to fetch Stripe plans', ['error' => $e->getMessage()]);

            return $this->json([
                'plans' => [],
                'publishableKey' => $this->stripePublishableKey,
                'configured' => true,
            ]);
        }
    }

    /**
     * Create a Stripe Checkout session for subscription.
     * Self-hosted: returns redirect to SaaS billing page.
     */
    #[Route('/checkout', name: 'billing_checkout', methods: ['POST'])]
    public function checkout(Request $request): JsonResponse
    {
        $org = $this->organizationContext->getOrganization();
        if (!$org) {
            return $this->json(['error' => 'Organization not found'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission('org.manage_billing')) {
            return $this->json(['error' => 'Permission denied'], Response::HTTP_FORBIDDEN);
        }

        // Self-hosted: redirect to SaaS billing
        if ($this->licenseValidationService->isSelfHosted()) {
            return $this->json([
                'url' => $this->licenseValidationService->getBillingUrl(),
                'selfHosted' => true,
            ]);
        }

        $data = json_decode($request->getContent(), true);
        $priceId = $data['priceId'] ?? null;

        if (!$priceId) {
            return $this->json(['error' => 'priceId is required'], Response::HTTP_BAD_REQUEST);
        }

        try {
            // If org already has an active subscription, change plan instead of creating new checkout
            if ($org->getStripeSubscriptionId() && $org->hasActiveSubscription()) {
                $this->stripeService->changeSubscriptionPlan($org, $priceId);

                return $this->json(['status' => 'plan_changed']);
            }

            $url = $this->stripeService->createCheckoutSession($org, $priceId);

            return $this->json(['url' => $url]);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        } catch (\Exception $e) {
            $this->logger->error('Checkout session failed', ['error' => $e->getMessage()]);

            return $this->json(['error' => 'Failed to create checkout session'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Create a Stripe Customer Portal session.
     * Self-hosted: returns redirect to SaaS billing page.
     */
    #[Route('/portal', name: 'billing_portal', methods: ['POST'])]
    public function portal(): JsonResponse
    {
        $org = $this->organizationContext->getOrganization();
        if (!$org) {
            return $this->json(['error' => 'Organization not found'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission('org.manage_billing')) {
            return $this->json(['error' => 'Permission denied'], Response::HTTP_FORBIDDEN);
        }

        if ($this->licenseValidationService->isSelfHosted()) {
            return $this->json([
                'url' => $this->licenseValidationService->getBillingUrl(),
                'selfHosted' => true,
            ]);
        }

        try {
            $url = $this->stripeService->createBillingPortalSession($org);

            return $this->json(['url' => $url]);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (\Exception $e) {
            $this->logger->error('Portal session failed', ['error' => $e->getMessage()]);

            return $this->json(['error' => 'Failed to create portal session'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get current subscription status.
     */
    #[Route('/subscription', name: 'billing_subscription', methods: ['GET'])]
    public function subscription(): JsonResponse
    {
        $org = $this->organizationContext->getOrganization();
        if (!$org) {
            return $this->json(['error' => 'Organization not found'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission('org.manage_billing')) {
            return $this->json(['error' => 'Permission denied'], Response::HTTP_FORBIDDEN);
        }

        // Live-sync from Stripe to ensure local DB is up-to-date
        if (!$this->licenseValidationService->isSelfHosted() && $org->getStripeCustomerId()) {
            try {
                // Fetch all active subscriptions for this customer
                $subs = \Stripe\Subscription::all([
                    'customer' => $org->getStripeCustomerId(),
                    'limit' => 10,
                ]);

                if (count($subs->data) > 0) {
                    // Sync the most recent subscription
                    $primary = $subs->data[0];
                    $this->stripeService->syncSubscriptionStatus($org, $primary);

                    // Cancel duplicate subscriptions (keep only the first one)
                    for ($i = 1; $i < count($subs->data); $i++) {
                        try {
                            $subs->data[$i]->cancel();
                            $this->logger->info('Canceled duplicate subscription', [
                                'subscription' => $subs->data[$i]->id,
                                'organization' => (string) $org->getId(),
                            ]);
                        } catch (\Exception $e) {
                            $this->logger->warning('Failed to cancel duplicate subscription', [
                                'subscription' => $subs->data[$i]->id,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                } elseif ($org->getStripeSubscriptionId()) {
                    // No active subscriptions on Stripe but we have one stored — reset
                    $org->setSubscriptionStatus('canceled');
                    $org->setPlan(LicenseManager::PLAN_FREEMIUM);
                    $org->setStripeSubscriptionId(null);
                    $org->setStripePriceId(null);
                    $org->setCurrentPeriodEnd(null);
                    $org->setCancelAtPeriodEnd(false);
                    $this->em->flush();
                    $this->logger->warning('No active Stripe subscriptions found, reset to freemium', [
                        'organization' => (string) $org->getId(),
                    ]);
                }
            } catch (\Exception $e) {
                $this->logger->warning('Failed to sync subscription from Stripe', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $planStatus = $this->licenseManager->getPlanStatus($org);

        $response = [
            ...$planStatus,
            'subscription' => [
                'stripeSubscriptionId' => $org->getStripeSubscriptionId(),
                'stripePriceId' => $org->getStripePriceId(),
                'status' => $org->getSubscriptionStatus(),
                'currentPeriodEnd' => $org->getCurrentPeriodEnd()?->format('c'),
                'cancelAtPeriodEnd' => $org->isCancelAtPeriodEnd(),
            ],
        ];

        if ($this->licenseValidationService->isSelfHosted()) {
            $response['selfHosted'] = true;
            $response['billingUrl'] = $this->licenseValidationService->getBillingUrl();
        }

        return $this->json($response);
    }

    /**
     * Change subscription plan (upgrade/downgrade).
     * Updates the existing Stripe subscription to a new price.
     */
    #[Route('/change-plan', name: 'billing_change_plan', methods: ['POST'])]
    public function changePlan(Request $request): JsonResponse
    {
        $org = $this->organizationContext->getOrganization();
        if (!$org) {
            return $this->json(['error' => 'Organization not found'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission('org.manage_billing')) {
            return $this->json(['error' => 'Permission denied'], Response::HTTP_FORBIDDEN);
        }

        if ($this->licenseValidationService->isSelfHosted()) {
            return $this->json([
                'url' => $this->licenseValidationService->getBillingUrl(),
                'selfHosted' => true,
            ]);
        }

        $data = json_decode($request->getContent(), true);
        $priceId = $data['priceId'] ?? null;

        if (!$priceId) {
            return $this->json(['error' => 'priceId is required'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->stripeService->changeSubscriptionPlan($org, $priceId);

            return $this->json(['status' => 'plan_changed']);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        } catch (\Exception $e) {
            $this->logger->error('Plan change failed', ['error' => $e->getMessage()]);

            return $this->json(['error' => 'Failed to change plan'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Cancel subscription at period end.
     * Self-hosted: redirect to SaaS.
     */
    #[Route('/cancel', name: 'billing_cancel', methods: ['POST'])]
    public function cancel(): JsonResponse
    {
        $org = $this->organizationContext->getOrganization();
        if (!$org) {
            return $this->json(['error' => 'Organization not found'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission('org.manage_billing')) {
            return $this->json(['error' => 'Permission denied'], Response::HTTP_FORBIDDEN);
        }

        if ($this->licenseValidationService->isSelfHosted()) {
            return $this->json([
                'error' => 'Manage your subscription at ' . $this->licenseValidationService->getBillingUrl(),
                'billingUrl' => $this->licenseValidationService->getBillingUrl(),
                'selfHosted' => true,
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->stripeService->cancelSubscription($org);

            return $this->json(['status' => 'canceled_at_period_end']);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Get current usage metrics for invoices, companies, and users.
     */
    #[Route('/usage', name: 'billing_usage', methods: ['GET'])]
    public function usage(): JsonResponse
    {
        $org = $this->organizationContext->getOrganization();
        if (!$org) {
            return $this->json(['error' => 'Organization not found'], Response::HTTP_NOT_FOUND);
        }

        $usage = $this->usageTrackingService->getUsagePercentage($org);

        return $this->json(['usage' => $usage]);
    }

    /**
     * Resume a canceled subscription.
     * Self-hosted: redirect to SaaS.
     */
    #[Route('/resume', name: 'billing_resume', methods: ['POST'])]
    public function resume(): JsonResponse
    {
        $org = $this->organizationContext->getOrganization();
        if (!$org) {
            return $this->json(['error' => 'Organization not found'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission('org.manage_billing')) {
            return $this->json(['error' => 'Permission denied'], Response::HTTP_FORBIDDEN);
        }

        if ($this->licenseValidationService->isSelfHosted()) {
            return $this->json([
                'error' => 'Manage your subscription at ' . $this->licenseValidationService->getBillingUrl(),
                'billingUrl' => $this->licenseValidationService->getBillingUrl(),
                'selfHosted' => true,
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->stripeService->resumeSubscription($org);

            return $this->json(['status' => 'resumed']);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }
}
