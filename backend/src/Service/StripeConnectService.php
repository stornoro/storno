<?php

namespace App\Service;

use App\Entity\Company;
use App\Entity\Invoice;
use App\Entity\InvoiceShareToken;
use App\Entity\StripeConnectAccount;
use App\Repository\StripeConnectAccountRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Stripe\Account;
use Stripe\AccountLink;
use Stripe\Checkout\Session as CheckoutSession;
use Stripe\Stripe;
use Stripe\Webhook;

class StripeConnectService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StripeConnectAccountRepository $connectAccountRepository,
        private readonly LoggerInterface $logger,
        private readonly string $stripeSecretKey,
        private readonly string $stripeConnectWebhookSecret,
        private readonly string $frontendUrl,
        private readonly float $platformFeePercent = 0,
    ) {
        Stripe::setApiKey($this->stripeSecretKey);
    }

    /**
     * Create a Stripe Standard Connect account for a company.
     */
    public function createAccount(Company $company): StripeConnectAccount
    {
        $existing = $this->connectAccountRepository->findByCompany($company);
        if ($existing) {
            return $existing;
        }

        $account = Account::create([
            'type' => 'standard',
            'country' => 'RO',
            'metadata' => [
                'company_id' => (string) $company->getId(),
            ],
        ]);

        $connectAccount = new StripeConnectAccount();
        $connectAccount->setCompany($company);
        $connectAccount->setStripeAccountId($account->id);

        $this->em->persist($connectAccount);
        $this->em->flush();

        $this->logger->info('Stripe Connect account created', [
            'company' => (string) $company->getId(),
            'stripe_account' => $account->id,
        ]);

        return $connectAccount;
    }

    /**
     * Create an onboarding link for a Connect account.
     */
    public function createOnboardingLink(Company $company): string
    {
        $connectAccount = $this->connectAccountRepository->findByCompany($company);
        if (!$connectAccount) {
            $connectAccount = $this->createAccount($company);
        }

        $accountLink = AccountLink::create([
            'account' => $connectAccount->getStripeAccountId(),
            'refresh_url' => $this->frontendUrl . '/settings/payments?status=refresh',
            'return_url' => $this->frontendUrl . '/settings/payments?status=complete',
            'type' => 'account_onboarding',
        ]);

        return $accountLink->url;
    }

    /**
     * Create a login link for the Connect account dashboard.
     */
    public function createDashboardLink(Company $company): string
    {
        $connectAccount = $this->connectAccountRepository->findByCompany($company);
        if (!$connectAccount) {
            throw new \RuntimeException('Company has no Stripe Connect account.');
        }

        // Standard accounts manage their dashboard directly on Stripe
        $account = Account::retrieve($connectAccount->getStripeAccountId());
        if (($account->type ?? '') === 'standard') {
            return 'https://dashboard.stripe.com';
        }

        // Express/Custom accounts use login links
        $loginLink = Account::createLoginLink($connectAccount->getStripeAccountId());

        return $loginLink->url;
    }

    /**
     * Create a Checkout Session on the connected account for invoice payment.
     */
    public function createPaymentSession(Invoice $invoice, InvoiceShareToken $shareToken, ?string $requestedAmount = null): string
    {
        $company = $invoice->getCompany();
        $connectAccount = $this->connectAccountRepository->findByCompany($company);

        if (!$connectAccount || !$connectAccount->isChargesEnabled()) {
            throw new \RuntimeException('Company does not have an active Stripe Connect account.');
        }

        // Calculate remaining amount (total - already paid)
        $total = (float) $invoice->getTotal();
        $paid = (float) $invoice->getAmountPaid();
        $remaining = $total - $paid;

        if ($remaining <= 0) {
            throw new \RuntimeException('Invoice is already fully paid.');
        }

        // Handle partial payment amount
        if ($requestedAmount !== null) {
            if (!$connectAccount->isAllowPartialPayments()) {
                throw new \RuntimeException('Partial payments are not enabled for this account.');
            }

            $amount = (float) $requestedAmount;
            if ($amount <= 0) {
                throw new \RuntimeException('Payment amount must be greater than zero.');
            }
            if ($amount > $remaining) {
                throw new \RuntimeException('Payment amount cannot exceed the remaining balance.');
            }

            $remaining = $amount;
        }

        // Convert to cents
        $amountInCents = (int) round($remaining * 100);

        $sessionParams = [
            'mode' => 'payment',
            'line_items' => [[
                'price_data' => [
                    'currency' => strtolower($invoice->getCurrency() ?? 'ron'),
                    'product_data' => [
                        'name' => 'Factura ' . $invoice->getNumber(),
                        'description' => sprintf(
                            '%s - %s',
                            $company->getName(),
                            $invoice->getNumber(),
                        ),
                    ],
                    'unit_amount' => $amountInCents,
                ],
                'quantity' => 1,
            ]],
            'success_url' => $this->frontendUrl . '/share/' . $shareToken->getToken() . '?payment=success',
            'cancel_url' => $this->frontendUrl . '/share/' . $shareToken->getToken() . '?payment=canceled',
            'metadata' => [
                'invoice_id' => (string) $invoice->getId(),
                'share_token' => $shareToken->getToken(),
                'company_id' => (string) $company->getId(),
            ],
        ];

        // Apply platform fee if configured
        if ($this->platformFeePercent > 0) {
            $feeAmount = (int) round($amountInCents * $this->platformFeePercent / 100);
            $sessionParams['payment_intent_data'] = [
                'application_fee_amount' => $feeAmount,
            ];
        }

        $session = CheckoutSession::create($sessionParams, [
            'stripe_account' => $connectAccount->getStripeAccountId(),
        ]);

        // Save the session ID on the share token
        $shareToken->setStripeSessionId($session->id);
        $this->em->flush();

        $this->logger->info('Payment session created for invoice', [
            'invoice' => (string) $invoice->getId(),
            'session_id' => $session->id,
            'amount' => $amountInCents,
        ]);

        return $session->url;
    }

    /**
     * Sync account status from Stripe API.
     */
    public function syncAccountStatus(StripeConnectAccount $connectAccount): void
    {
        $account = Account::retrieve($connectAccount->getStripeAccountId());

        $connectAccount->setChargesEnabled($account->charges_enabled ?? false);
        $connectAccount->setPayoutsEnabled($account->payouts_enabled ?? false);
        $connectAccount->setDetailsSubmitted($account->details_submitted ?? false);

        if ($connectAccount->isFullyActive()) {
            $connectAccount->setOnboardingComplete(true);
        }

        $this->em->flush();

        $this->logger->info('Connect account status synced', [
            'stripe_account' => $connectAccount->getStripeAccountId(),
            'charges_enabled' => $connectAccount->isChargesEnabled(),
            'payouts_enabled' => $connectAccount->isPayoutsEnabled(),
        ]);
    }

    /**
     * Disconnect (remove) a Connect account.
     */
    public function disconnect(Company $company): void
    {
        $connectAccount = $this->connectAccountRepository->findByCompany($company);
        if (!$connectAccount) {
            return;
        }

        $this->em->remove($connectAccount);
        $this->em->flush();

        $this->logger->info('Stripe Connect account disconnected', [
            'company' => (string) $company->getId(),
            'stripe_account' => $connectAccount->getStripeAccountId(),
        ]);
    }

    /**
     * Verify and parse a Connect webhook payload.
     */
    public function constructWebhookEvent(string $payload, string $sigHeader): \Stripe\Event
    {
        return Webhook::constructEvent($payload, $sigHeader, $this->stripeConnectWebhookSecret);
    }
}
