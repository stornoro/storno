<?php

namespace App\Service;

use App\Entity\Organization;
use App\Repository\OrganizationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Stripe\Checkout\Session as CheckoutSession;
use Stripe\BillingPortal\Session as PortalSession;
use Stripe\Customer;
use Stripe\Stripe;
use Stripe\Subscription;
use Stripe\Webhook;

class StripeService
{
    private const EARLY_ADOPTER_LIMIT = 100;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly OrganizationRepository $organizationRepository,
        private readonly LoggerInterface $logger,
        private readonly string $stripeSecretKey,
        private readonly string $stripeWebhookSecret,
        private readonly string $frontendUrl,
        private readonly string $earlyAdopterCouponId = '',
    ) {
        Stripe::setApiKey($this->stripeSecretKey);
    }

    /**
     * Create a Stripe customer from Organization billing info.
     */
    public function createCustomer(Organization $org): Customer
    {
        if ($org->getStripeCustomerId()) {
            return Customer::retrieve($org->getStripeCustomerId());
        }

        $customer = Customer::create([
            'name' => $org->getName(),
            'metadata' => [
                'organization_id' => (string) $org->getId(),
            ],
        ]);

        $org->setStripeCustomerId($customer->id);
        $this->em->flush();

        $this->logger->info('Stripe customer created', [
            'organization' => (string) $org->getId(),
            'stripe_customer' => $customer->id,
        ]);

        return $customer;
    }

    /**
     * Create a Stripe Checkout Session for subscription upgrade.
     */
    public function createCheckoutSession(Organization $org, string $priceId): string
    {
        $customer = $this->createCustomer($org);

        $params = [
            'customer' => $customer->id,
            'mode' => 'subscription',
            'line_items' => [[
                'price' => $priceId,
                'quantity' => 1,
            ]],
            'success_url' => $this->frontendUrl . '/settings/billing?session_id={CHECKOUT_SESSION_ID}&status=success',
            'cancel_url' => $this->frontendUrl . '/settings/billing?status=canceled',
            'metadata' => [
                'organization_id' => (string) $org->getId(),
            ],
            'subscription_data' => [
                'metadata' => [
                    'organization_id' => (string) $org->getId(),
                ],
            ],
        ];

        // Apply early adopter bonus (50% off first payment) if eligible
        if ($this->isEarlyAdopterEligible($org)) {
            $params['discounts'] = [['coupon' => $this->earlyAdopterCouponId]];
        }

        $session = CheckoutSession::create($params);

        $this->logger->info('Stripe checkout session created', [
            'organization' => (string) $org->getId(),
            'session_id' => $session->id,
            'price_id' => $priceId,
        ]);

        return $session->url;
    }

    /**
     * Create a Stripe Customer Portal session for managing subscription.
     */
    public function createBillingPortalSession(Organization $org): string
    {
        if (!$org->getStripeCustomerId()) {
            throw new \RuntimeException('Organization has no Stripe customer.');
        }

        $session = PortalSession::create([
            'customer' => $org->getStripeCustomerId(),
            'return_url' => $this->frontendUrl . '/settings/billing',
        ]);

        return $session->url;
    }

    /**
     * Change an existing subscription to a different price (upgrade/downgrade).
     * Uses proration so the customer pays/receives credit for the difference.
     */
    public function changeSubscriptionPlan(Organization $org, string $newPriceId): void
    {
        if (!$org->getStripeSubscriptionId()) {
            throw new \RuntimeException('Organization has no active subscription.');
        }

        $subscription = Subscription::retrieve($org->getStripeSubscriptionId());

        if ($subscription->status === 'canceled') {
            throw new \RuntimeException('Subscription is canceled. Please create a new subscription.');
        }

        // Get the current subscription item to swap
        $currentItem = $subscription->items->data[0] ?? null;
        if (!$currentItem) {
            throw new \RuntimeException('Subscription has no items.');
        }

        // Don't allow changing to the same price
        if ($currentItem->price->id === $newPriceId) {
            throw new \RuntimeException('Already subscribed to this plan.');
        }

        // Update the subscription item with the new price
        $updated = Subscription::update($org->getStripeSubscriptionId(), [
            'items' => [[
                'id' => $currentItem->id,
                'price' => $newPriceId,
            ]],
            'proration_behavior' => 'create_prorations',
        ]);

        // If the subscription was set to cancel, undo that since the user is actively changing plans
        if ($org->isCancelAtPeriodEnd()) {
            Subscription::update($org->getStripeSubscriptionId(), [
                'cancel_at_period_end' => false,
            ]);
            $org->setCancelAtPeriodEnd(false);
        }

        $this->syncSubscriptionStatus($org, $updated);

        $this->logger->info('Subscription plan changed', [
            'organization' => (string) $org->getId(),
            'new_price_id' => $newPriceId,
            'new_plan' => $this->resolvePlanFromPriceId($newPriceId),
        ]);
    }

    /**
     * Cancel subscription at period end.
     */
    public function cancelSubscription(Organization $org): void
    {
        if (!$org->getStripeSubscriptionId()) {
            throw new \RuntimeException('Organization has no active subscription.');
        }

        $subscription = Subscription::update($org->getStripeSubscriptionId(), [
            'cancel_at_period_end' => true,
        ]);

        $org->setCancelAtPeriodEnd(true);
        $this->em->flush();

        $this->logger->info('Subscription cancel scheduled', [
            'organization' => (string) $org->getId(),
            'subscription' => $subscription->id,
        ]);
    }

    /**
     * Resume a subscription that was set to cancel at period end.
     */
    public function resumeSubscription(Organization $org): void
    {
        if (!$org->getStripeSubscriptionId()) {
            throw new \RuntimeException('Organization has no subscription.');
        }

        $subscription = Subscription::update($org->getStripeSubscriptionId(), [
            'cancel_at_period_end' => false,
        ]);

        $org->setCancelAtPeriodEnd(false);
        $this->em->flush();

        $this->logger->info('Subscription resumed', [
            'organization' => (string) $org->getId(),
            'subscription' => $subscription->id,
        ]);
    }

    /**
     * Sync local subscription status from a Stripe Subscription object.
     */
    public function syncSubscriptionStatus(Organization $org, Subscription $stripeSubscription): void
    {
        $org->setSubscriptionStatus($stripeSubscription->status);

        // Stripe has two cancellation mechanisms:
        // 1. cancel_at_period_end (boolean) — cancels when period ends
        // 2. cancel_at (timestamp) — cancels at a specific date (used by Customer Portal)
        $pendingCancel = $stripeSubscription->cancel_at_period_end || $stripeSubscription->cancel_at !== null;
        $org->setCancelAtPeriodEnd($pendingCancel);

        // Use cancel_at date if set, otherwise current_period_end
        if ($pendingCancel && $stripeSubscription->cancel_at) {
            $org->setCurrentPeriodEnd(new \DateTimeImmutable('@' . $stripeSubscription->cancel_at));
        } elseif (isset($stripeSubscription->items->data[0]->current_period_end)) {
            $org->setCurrentPeriodEnd(
                new \DateTimeImmutable('@' . $stripeSubscription->items->data[0]->current_period_end)
            );
        }

        // Canceled subscription → downgrade to freemium, clear Stripe fields
        if ($stripeSubscription->status === 'canceled') {
            $org->setPlan(LicenseManager::PLAN_FREEMIUM);
            $org->setStripeSubscriptionId(null);
            $org->setStripePriceId(null);
            $org->setCancelAtPeriodEnd(false);

            $this->em->flush();

            $this->logger->info('Subscription canceled, downgraded to freemium', [
                'organization' => (string) $org->getId(),
            ]);

            return;
        }

        $org->setStripeSubscriptionId($stripeSubscription->id);
        $org->setStripePriceId($stripeSubscription->items->data[0]->price->id ?? null);

        // Map Stripe price to plan name
        $plan = $this->resolvePlanFromPriceId($stripeSubscription->items->data[0]->price->id ?? '');
        if ($plan) {
            $org->setPlan($plan);
        }

        $this->em->flush();

        $this->logger->info('Subscription status synced', [
            'organization' => (string) $org->getId(),
            'status' => $stripeSubscription->status,
            'plan' => $plan,
            'pendingCancel' => $pendingCancel,
        ]);
    }

    /**
     * Verify and parse a webhook payload.
     *
     * @return \Stripe\Event
     */
    public function constructWebhookEvent(string $payload, string $sigHeader): \Stripe\Event
    {
        return Webhook::constructEvent($payload, $sigHeader, $this->stripeWebhookSecret);
    }

    /**
     * Check if an organization is eligible for the early adopter discount.
     */
    public function isEarlyAdopterEligible(Organization $org): bool
    {
        if (empty($this->earlyAdopterCouponId)) {
            return false;
        }

        if ($org->hasEarlyAdopterDiscount()) {
            return false;
        }

        return $this->organizationRepository->countEarlyAdopters() < self::EARLY_ADOPTER_LIMIT;
    }

    /**
     * Map a Stripe Price ID to a local plan name.
     * Price IDs are configured in Stripe Dashboard and referenced here via env/config.
     * Uses price metadata 'plan' key, falling back to product metadata.
     */
    public function resolvePlanFromPriceId(string $priceId): ?string
    {
        try {
            $price = \Stripe\Price::retrieve(['id' => $priceId, 'expand' => ['product']]);

            // Check price metadata first, then product metadata
            $plan = $price->metadata['plan'] ?? null;
            if (!$plan && $price->product) {
                $plan = $price->product->metadata['plan'] ?? null;
            }

            // Normalize legacy 'pro' → 'professional'
            if ($plan === 'pro') {
                $plan = LicenseManager::PLAN_PROFESSIONAL;
            }

            if ($plan && \in_array($plan, LicenseManager::ALL_PLANS, true)) {
                return $plan;
            }

            $this->logger->warning('Could not resolve plan from Stripe price', [
                'price_id' => $priceId,
                'metadata_plan' => $plan,
            ]);

            return null;
        } catch (\Exception $e) {
            $this->logger->error('Failed to resolve plan from price', [
                'price_id' => $priceId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
