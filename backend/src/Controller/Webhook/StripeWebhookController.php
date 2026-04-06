<?php

namespace App\Controller\Webhook;

use App\Enum\MessageKey;
use App\Message\SendDunningEmailMessage;
use App\Message\SendPlanChangedMessage;
use App\Message\SendSubscriptionCancelledMessage;
use App\Message\SendSubscriptionConfirmationMessage;
use App\Message\SendSubscriptionRenewalMessage;
use App\Entity\LicenseKey;
use App\Repository\InvoiceShareTokenRepository;
use App\Repository\LicenseKeyRepository;
use App\Repository\OrganizationRepository;
use App\Repository\PaymentRepository;
use App\Repository\ProcessedWebhookEventRepository;
use App\Repository\StripeConnectAccountRepository;
use App\Service\BillingInvoiceService;
use App\Service\CompanyReadOnlyService;
use App\Service\LicenseManager;
use App\Service\NotificationService;
use App\Service\PaymentService;
use App\Service\StripeService;
use App\Service\Webhook\WebhookDispatcher;
use Psr\Log\LoggerInterface;
use Stripe\Subscription;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

class StripeWebhookController extends AbstractController
{
    public function __construct(
        private readonly StripeService $stripeService,
        private readonly OrganizationRepository $organizationRepository,
        private readonly BillingInvoiceService $billingInvoiceService,
        private readonly LoggerInterface $logger,
        private readonly ProcessedWebhookEventRepository $processedWebhookEventRepository,
        private readonly MessageBusInterface $bus,
        private readonly CompanyReadOnlyService $companyReadOnlyService,
        private readonly LicenseKeyRepository $licenseKeyRepository,
        private readonly InvoiceShareTokenRepository $shareTokenRepository,
        private readonly PaymentRepository $paymentRepository,
        private readonly PaymentService $paymentService,
        private readonly StripeConnectAccountRepository $connectAccountRepository,
        private readonly WebhookDispatcher $webhookDispatcher,
        private readonly NotificationService $notificationService,
    ) {}

    #[Route('/webhook/stripe', name: 'webhook_stripe', methods: ['POST'])]
    public function handle(Request $request): Response
    {
        $payload = $request->getContent();
        $sigHeader = $request->headers->get('Stripe-Signature', '');

        try {
            $event = $this->stripeService->constructWebhookEvent($payload, $sigHeader);
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            $this->logger->warning('Stripe webhook signature verification failed', [
                'error' => $e->getMessage(),
            ]);

            return new JsonResponse(['error' => 'Invalid signature'], Response::HTTP_BAD_REQUEST);
        } catch (\Exception $e) {
            $this->logger->error('Stripe webhook error', ['error' => $e->getMessage()]);

            return new JsonResponse(['error' => 'Webhook error'], Response::HTTP_BAD_REQUEST);
        }

        $this->logger->info('Stripe webhook received', [
            'type' => $event->type,
            'id' => $event->id,
        ]);

        // Idempotency guard — skip events already processed
        if ($this->processedWebhookEventRepository->isProcessed($event->id)) {
            $this->logger->info('Stripe webhook already processed, skipping', [
                'id' => $event->id,
                'type' => $event->type,
            ]);

            return new JsonResponse(['status' => 'already_processed']);
        }

        try {
            match ($event->type) {
                'checkout.session.completed' => $this->handleCheckoutCompleted($event->data->object),
                'customer.subscription.updated' => $this->handleSubscriptionUpdated($event->data->object),
                'customer.subscription.deleted' => $this->handleSubscriptionDeleted($event->data->object),
                'invoice.paid' => $this->handleInvoicePaid($event->data->object),
                'invoice.payment_failed' => $this->handlePaymentFailed($event->data->object),
                default => $this->logger->info('Unhandled Stripe event type: ' . $event->type),
            };
        } catch (\Throwable $e) {
            $this->logger->error('Error processing Stripe webhook', [
                'type' => $event->type,
                'error' => $e->getMessage(),
            ]);

            // Return 200 to prevent Stripe retries on processing errors
            return new JsonResponse(['status' => 'error_logged']);
        }

        $this->processedWebhookEventRepository->markProcessed($event->id, $event->type);

        return new JsonResponse(['status' => 'ok']);
    }

    private function handleCheckoutCompleted(object $session): void
    {
        // Connect payment checkout (invoice share link payment)
        $shareTokenValue = $session->metadata->share_token ?? null;
        if ($shareTokenValue) {
            $this->handleConnectCheckoutCompleted($session);
            return;
        }

        // Subscription checkout
        $orgId = $session->metadata->organization_id ?? null;
        if (!$orgId) {
            $this->logger->warning('Checkout session missing organization_id metadata');
            return;
        }

        $org = $this->organizationRepository->find($orgId);
        if (!$org) {
            $this->logger->warning('Organization not found for checkout session', ['org_id' => $orgId]);
            return;
        }

        // Retrieve the full subscription
        if ($session->subscription) {
            $subscription = Subscription::retrieve($session->subscription);
            $this->stripeService->syncSubscriptionStatus($org, $subscription);

            // Mark early adopter discount if a coupon was applied
            if ($subscription->discount && !$org->hasEarlyAdopterDiscount()) {
                $org->setEarlyAdopterDiscount(true);
                $this->organizationRepository->getEntityManager()->flush();
            }

            $this->logger->info('Subscription activated via checkout', [
                'organization' => $orgId,
                'subscription' => $subscription->id,
            ]);
        }
    }

    private function handleConnectCheckoutCompleted(object $session): void
    {
        $shareTokenValue = $session->metadata->share_token ?? null;
        $invoiceId = $session->metadata->invoice_id ?? null;
        $paymentIntentId = $session->payment_intent ?? null;

        if (!$shareTokenValue || !$invoiceId) {
            $this->logger->warning('Connect checkout missing metadata', [
                'session_id' => $session->id,
            ]);
            return;
        }

        $shareToken = $this->shareTokenRepository->findValidByToken($shareTokenValue);
        if (!$shareToken) {
            $this->logger->warning('Share token not found for Connect checkout', [
                'token' => $shareTokenValue,
            ]);
            return;
        }

        $invoice = $shareToken->getInvoice();
        if (!$invoice) {
            return;
        }

        if ($paymentIntentId) {
            $shareToken->setStripePaymentIntentId($paymentIntentId);
        }

        $amountPaid = ($session->amount_total ?? 0) / 100;
        if ($amountPaid > 0) {
            // Idempotency guard
            if ($paymentIntentId && $this->paymentRepository->findByReference($paymentIntentId)) {
                $this->logger->info('Payment already recorded, skipping (idempotent)', [
                    'payment_intent' => $paymentIntentId,
                ]);
                return;
            }

            try {
                $payment = $this->paymentService->recordPayment($invoice, [
                    'amount' => number_format($amountPaid, 2, '.', ''),
                    'paymentMethod' => 'stripe',
                    'reference' => $paymentIntentId,
                    'paymentDate' => (new \DateTime())->format('Y-m-d'),
                ]);

                $this->logger->info('Payment recorded via Stripe Connect', [
                    'invoice' => (string) $invoice->getId(),
                    'amount' => $amountPaid,
                    'payment_intent' => $paymentIntentId,
                ]);

                // Dispatch notifications
                $company = $invoice->getCompany();
                if ($company) {
                    $notifData = [
                        'paymentId' => $payment->getId()->toRfc4122(),
                        'invoiceId' => $invoice->getId()->toRfc4122(),
                        'invoice_number' => $invoice->getNumber(),
                        'amount' => $payment->getAmount(),
                        'paymentMethod' => $payment->getPaymentMethod(),
                        'currency' => $invoice->getCurrency(),
                        'companyId' => (string) $company->getId(),
                    ];

                    // External webhook dispatch
                    $connectAccount = $this->connectAccountRepository->findByCompany($company);
                    if ($connectAccount && $connectAccount->isNotifyOnPayment()) {
                        $this->webhookDispatcher->dispatchForCompany($company, 'payment.received', $notifData);
                    }

                    // In-app + email notification to organization members
                    $org = $company->getOrganization();
                    if ($org) {
                        $title = sprintf('Payment received: %s %s', $payment->getAmount(), $invoice->getCurrency());
                        $message = sprintf('Invoice %s received a payment of %s %s via Stripe.', $invoice->getNumber(), $payment->getAmount(), $invoice->getCurrency());
                        foreach ($org->getMemberships() as $membership) {
                            $user = $membership->getUser();
                            if ($user) {
                                $notifDataWithKeys = array_merge($notifData, [
                                    'titleKey' => MessageKey::TITLE_PAYMENT_RECEIVED,
                                    'messageKey' => MessageKey::MSG_PAYMENT_RECEIVED,
                                    'messageParams' => [
                                        'number' => $invoice->getNumber(),
                                        'amount' => $payment->getAmount(),
                                        'currency' => $invoice->getCurrency(),
                                    ],
                                ]);
                                $this->notificationService->createNotification($user, 'payment.received', $title, $message, $notifDataWithKeys);
                            }
                        }
                    }
                }
            } catch (\Exception $e) {
                $this->logger->error('Failed to record Connect payment', [
                    'invoice' => (string) $invoice->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->organizationRepository->getEntityManager()->flush();
    }

    /**
     * Handle Stripe invoice.paid — generate a Storno.ro invoice for every successful payment.
     * This covers both the initial checkout payment and recurring subscription renewals.
     */
    private function handleInvoicePaid(object $stripeInvoice): void
    {
        // Only process subscription invoices
        if (($stripeInvoice->billing_reason ?? null) === null) {
            return;
        }

        $customerId = $stripeInvoice->customer ?? null;
        if (!$customerId) {
            return;
        }

        $org = $this->organizationRepository->findOneBy(['stripeCustomerId' => $customerId]);
        if (!$org) {
            $this->logger->warning('Organization not found for paid invoice', [
                'customer_id' => $customerId,
                'stripe_invoice_id' => $stripeInvoice->id ?? 'unknown',
            ]);
            return;
        }

        // Extract payment details from the Stripe invoice
        $amount = $stripeInvoice->amount_paid ?? 0;
        $currency = $stripeInvoice->currency ?? 'ron';

        if ($amount <= 0) {
            return; // Skip zero-amount invoices (e.g. trial start)
        }

        // Resolve plan name, interval, and buyer company from subscription
        $planName = $org->getPlan() ?: 'starter'; // use org's synced plan as primary source
        $interval = 'month'; // fallback
        $buyerCompanyId = null;
        $subscriptionId = $stripeInvoice->subscription ?? null;

        if ($subscriptionId) {
            try {
                $subscription = Subscription::retrieve($subscriptionId);
                $priceId = $subscription->items->data[0]->price->id ?? null;
                if ($priceId) {
                    $resolved = $this->stripeService->resolvePlanFromPriceId($priceId);
                    if ($resolved) {
                        $planName = $resolved;
                    }
                    $interval = $subscription->items->data[0]->price->recurring->interval ?? 'month';
                }
                // Extract buyer company ID from subscription metadata
                $buyerCompanyId = $subscription->metadata->company_id ?? null;
            } catch (\Exception $e) {
                $this->logger->warning('Could not resolve plan from subscription', [
                    'subscription_id' => $subscriptionId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->billingInvoiceService->createSubscriptionInvoice(
            $org,
            $planName,
            $amount,
            $currency,
            $interval,
            $stripeInvoice->id,
            $buyerCompanyId,
        );

        // Auto-generate a license key for Business plan if the org doesn't have one yet
        if ($planName === LicenseManager::PLAN_BUSINESS) {
            $existingKeys = $this->licenseKeyRepository->findByOrganization($org);
            $hasActiveKey = false;
            foreach ($existingKeys as $key) {
                if ($key->isActive()) {
                    $hasActiveKey = true;
                    break;
                }
            }

            if (!$hasActiveKey) {
                $licenseKey = new LicenseKey();
                $licenseKey->setOrganization($org);
                $licenseKey->setInstanceName('Auto-generated');

                $this->organizationRepository->getEntityManager()->persist($licenseKey);
                $this->organizationRepository->getEntityManager()->flush();

                $this->logger->info('Auto-generated license key for Business subscription', [
                    'organization' => (string) $org->getId(),
                    'licenseKeyId' => (string) $licenseKey->getId(),
                ]);
            }
        }

        // Send email: full confirmation for first payment, lighter receipt for renewals
        $billingReason = $stripeInvoice->billing_reason ?? '';
        $isFirstPayment = \in_array($billingReason, ['subscription_create', 'subscription_threshold'], true);

        if ($isFirstPayment) {
            $generatedLicenseKey = isset($licenseKey) ? $licenseKey->getLicenseKey() : null;
            $this->bus->dispatch(new SendSubscriptionConfirmationMessage(
                (string) $org->getId(),
                $planName,
                $amount,
                $currency,
                $interval,
                $generatedLicenseKey,
            ));
        } else {
            $this->bus->dispatch(new SendSubscriptionRenewalMessage(
                (string) $org->getId(),
                $planName,
                $amount,
                $currency,
                $interval,
            ));
        }
    }

    private function handleSubscriptionUpdated(object $subscription): void
    {
        $org = $this->findOrganizationBySubscription($subscription);
        if (!$org) {
            return;
        }

        $oldPlan = $org->getPlan();

        // Re-retrieve as a proper Subscription object
        $stripeSubscription = Subscription::retrieve($subscription->id);
        $this->stripeService->syncSubscriptionStatus($org, $stripeSubscription);

        // Enforce company read-only limits after plan change
        $this->companyReadOnlyService->enforceCompanyLimits($org);

        // Notify if the plan actually changed (not just status updates like cancel_at_period_end)
        $newPlan = $org->getPlan();
        if ($oldPlan !== $newPlan && $newPlan !== LicenseManager::PLAN_FREEMIUM) {
            $this->bus->dispatch(new SendPlanChangedMessage(
                (string) $org->getId(),
                $oldPlan,
                $newPlan,
            ));
        }
    }

    private function handleSubscriptionDeleted(object $subscription): void
    {
        $org = $this->findOrganizationBySubscription($subscription);
        if (!$org) {
            return;
        }

        $previousPlan = $org->getPlan();

        $org->setSubscriptionStatus('canceled');
        $org->setPlan(LicenseManager::PLAN_FREEMIUM);
        $org->setStripeSubscriptionId(null);
        $org->setStripePriceId(null);
        $org->setCurrentPeriodEnd(null);
        $org->setCancelAtPeriodEnd(false);

        $this->organizationRepository->getEntityManager()->flush();

        $this->logger->info('Subscription deleted, downgraded to freemium', [
            'organization' => (string) $org->getId(),
        ]);

        // Enforce company read-only limits after downgrade
        $this->companyReadOnlyService->enforceCompanyLimits($org);

        // Notify org members
        $this->bus->dispatch(new SendSubscriptionCancelledMessage(
            (string) $org->getId(),
            $previousPlan,
        ));
    }

    private function handlePaymentFailed(object $invoice): void
    {
        $customerId = $invoice->customer ?? null;
        if (!$customerId) {
            return;
        }

        $org = $this->organizationRepository->findOneBy(['stripeCustomerId' => $customerId]);
        if (!$org) {
            $this->logger->warning('Organization not found for failed payment', [
                'customer_id' => $customerId,
            ]);
            return;
        }

        $org->setSubscriptionStatus('past_due');

        // Record the failure date and mark attempt 1 as sent so the dunning
        // cron command can accurately schedule attempts 2 and 3.
        $settings = $org->getSettings();
        $settings['dunning_failed_at'] = (new \DateTimeImmutable())->format('Y-m-d\TH:i:sP');
        $settings['dunning_attempt_1_sent'] = true;
        unset($settings['dunning_attempt_2_sent'], $settings['dunning_attempt_3_sent']);
        $org->setSettings($settings);

        $this->organizationRepository->getEntityManager()->flush();

        $this->logger->warning('Payment failed, subscription past_due', [
            'organization' => (string) $org->getId(),
        ]);

        $this->bus->dispatch(new SendDunningEmailMessage((string) $org->getId(), 1));
    }

    private function findOrganizationBySubscription(object $subscription): ?\App\Entity\Organization
    {
        // Try metadata first
        $orgId = $subscription->metadata->organization_id ?? null;
        if ($orgId) {
            $org = $this->organizationRepository->find($orgId);
            if ($org) {
                return $org;
            }
        }

        // Fall back to subscription ID lookup
        $org = $this->organizationRepository->findOneBy(['stripeSubscriptionId' => $subscription->id]);
        if (!$org) {
            $this->logger->warning('Organization not found for subscription', [
                'subscription_id' => $subscription->id,
            ]);
        }

        return $org;
    }
}
