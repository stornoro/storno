<?php

namespace App\Controller\Webhook;

use App\Repository\InvoiceShareTokenRepository;
use App\Repository\PaymentRepository;
use App\Repository\StripeConnectAccountRepository;
use App\Service\PaymentService;
use App\Service\StripeConnectService;
use App\Service\Webhook\WebhookDispatcher;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class StripeConnectWebhookController extends AbstractController
{
    public function __construct(
        private readonly StripeConnectService $connectService,
        private readonly StripeConnectAccountRepository $connectAccountRepository,
        private readonly InvoiceShareTokenRepository $shareTokenRepository,
        private readonly PaymentRepository $paymentRepository,
        private readonly PaymentService $paymentService,
        private readonly WebhookDispatcher $webhookDispatcher,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('/webhook/stripe-connect', name: 'webhook_stripe_connect', methods: ['POST'])]
    public function handle(Request $request): Response
    {
        $payload = $request->getContent();
        $sigHeader = $request->headers->get('Stripe-Signature', '');

        try {
            $event = $this->connectService->constructWebhookEvent($payload, $sigHeader);
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            $this->logger->warning('Stripe Connect webhook signature failed', [
                'error' => $e->getMessage(),
            ]);

            return new JsonResponse(['error' => 'Invalid signature'], Response::HTTP_BAD_REQUEST);
        } catch (\Exception $e) {
            $this->logger->error('Stripe Connect webhook error', ['error' => $e->getMessage()]);

            return new JsonResponse(['error' => 'Webhook error'], Response::HTTP_BAD_REQUEST);
        }

        $this->logger->info('Stripe Connect webhook received', [
            'type' => $event->type,
            'id' => $event->id,
            'account' => $event->account ?? null,
        ]);

        try {
            match ($event->type) {
                'checkout.session.completed' => $this->handleCheckoutCompleted($event->data->object),
                'account.updated' => $this->handleAccountUpdated($event->data->object),
                default => $this->logger->info('Unhandled Connect event: ' . $event->type),
            };
        } catch (\Throwable $e) {
            $this->logger->error('Error processing Connect webhook', [
                'type' => $event->type,
                'error' => $e->getMessage(),
            ]);

            return new JsonResponse(['status' => 'error_logged']);
        }

        return new JsonResponse(['status' => 'ok']);
    }

    private function handleCheckoutCompleted(object $session): void
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

        // Find the share token
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

        // Record the payment intent ID on the share token
        if ($paymentIntentId) {
            $shareToken->setStripePaymentIntentId($paymentIntentId);
        }

        // Record payment via PaymentService (with idempotency guard)
        $amountPaid = ($session->amount_total ?? 0) / 100;
        if ($amountPaid > 0) {
            // Prevent double payments on webhook retry
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

                // Dispatch payment notification if enabled
                $company = $invoice->getCompany();
                if ($company) {
                    $connectAccount = $this->connectAccountRepository->findByCompany($company);
                    if ($connectAccount && $connectAccount->isNotifyOnPayment()) {
                        $this->webhookDispatcher->dispatchForCompany($company, 'payment.received', [
                            'paymentId' => $payment->getId()->toRfc4122(),
                            'invoiceId' => $invoice->getId()->toRfc4122(),
                            'amount' => $payment->getAmount(),
                            'paymentMethod' => $payment->getPaymentMethod(),
                            'currency' => $invoice->getCurrency(),
                        ]);
                    }
                }
            } catch (\Exception $e) {
                $this->logger->error('Failed to record Connect payment', [
                    'invoice' => (string) $invoice->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->em->flush();
    }

    private function handleAccountUpdated(object $account): void
    {
        $connectAccount = $this->connectAccountRepository->findByStripeAccountId($account->id);
        if (!$connectAccount) {
            $this->logger->info('Connect account not found locally, skipping', [
                'stripe_account' => $account->id,
            ]);
            return;
        }

        $connectAccount->setChargesEnabled($account->charges_enabled ?? false);
        $connectAccount->setPayoutsEnabled($account->payouts_enabled ?? false);
        $connectAccount->setDetailsSubmitted($account->details_submitted ?? false);

        if ($connectAccount->isFullyActive()) {
            $connectAccount->setOnboardingComplete(true);
        }

        $this->em->flush();

        $this->logger->info('Connect account updated via webhook', [
            'stripe_account' => $account->id,
            'charges_enabled' => $connectAccount->isChargesEnabled(),
        ]);
    }
}
