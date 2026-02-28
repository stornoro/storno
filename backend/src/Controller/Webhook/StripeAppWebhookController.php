<?php

namespace App\Controller\Webhook;

use App\Repository\StripeAppTokenRepository;
use App\Service\StripeAppInvoiceService;
use Psr\Log\LoggerInterface;
use Stripe\Webhook;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class StripeAppWebhookController extends AbstractController
{
    public function __construct(
        private readonly StripeAppTokenRepository $tokenRepository,
        private readonly StripeAppInvoiceService $invoiceService,
        private readonly LoggerInterface $logger,
        private readonly string $stripeAppWebhookSecret,
    ) {}

    #[Route('/webhook/stripe-app', name: 'webhook_stripe_app', methods: ['POST'])]
    public function handle(Request $request): Response
    {
        $payload = $request->getContent();
        $sigHeader = $request->headers->get('Stripe-Signature', '');

        if (!$this->stripeAppWebhookSecret) {
            $this->logger->warning('Stripe App webhook secret not configured');

            return new JsonResponse(['error' => 'Webhook not configured'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        try {
            $event = Webhook::constructEvent($payload, $sigHeader, $this->stripeAppWebhookSecret);
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            $this->logger->warning('Stripe App webhook signature failed', [
                'error' => $e->getMessage(),
            ]);

            return new JsonResponse(['error' => 'Invalid signature'], Response::HTTP_BAD_REQUEST);
        } catch (\Exception $e) {
            $this->logger->error('Stripe App webhook error', ['error' => $e->getMessage()]);

            return new JsonResponse(['error' => 'Webhook error'], Response::HTTP_BAD_REQUEST);
        }

        $this->logger->info('Stripe App webhook received', [
            'type' => $event->type,
            'id' => $event->id,
            'account' => $event->account ?? null,
        ]);

        try {
            match ($event->type) {
                'invoice.finalized' => $this->handleInvoiceFinalized($event),
                'invoice.paid' => $this->handleInvoicePaid($event),
                default => $this->logger->info('Unhandled Stripe App event: ' . $event->type),
            };
        } catch (\Throwable $e) {
            $this->logger->error('Error processing Stripe App webhook', [
                'type' => $event->type,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Return 200 to prevent Stripe retries
            return new JsonResponse(['status' => 'error_logged']);
        }

        return new JsonResponse(['status' => 'ok']);
    }

    private function handleInvoiceFinalized(\Stripe\Event $event): void
    {
        $stripeInvoice = $event->data->object;
        $accountId = $event->account;

        if (!$accountId) {
            $this->logger->warning('Stripe App invoice.finalized: no account ID');

            return;
        }

        $appToken = $this->tokenRepository->findByStripeAccountId($accountId);

        if (!$appToken) {
            $this->logger->info('Stripe App invoice.finalized: no app token for account', [
                'account' => $accountId,
            ]);

            return;
        }

        if (!$appToken->getCompany()) {
            $this->logger->info('Stripe App invoice.finalized: no company configured', [
                'account' => $accountId,
            ]);

            return;
        }

        $invoiceData = json_decode(json_encode($stripeInvoice), true);
        $this->invoiceService->createFromStripeInvoice($appToken, $invoiceData);

        $this->logger->info('Stripe App: created e-Factura from finalized Stripe invoice', [
            'stripeInvoiceId' => $stripeInvoice->id ?? null,
            'account' => $accountId,
            'autoMode' => $appToken->isAutoMode(),
        ]);
    }

    private function handleInvoicePaid(\Stripe\Event $event): void
    {
        $stripeInvoice = $event->data->object;
        $accountId = $event->account;

        if (!$accountId) {
            return;
        }

        $appToken = $this->tokenRepository->findByStripeAccountId($accountId);

        if (!$appToken) {
            return;
        }

        $invoiceData = json_decode(json_encode($stripeInvoice), true);
        $this->invoiceService->recordPaymentFromStripeInvoice($appToken, $invoiceData);

        $this->logger->info('Stripe App: recorded payment from paid Stripe invoice', [
            'stripeInvoiceId' => $stripeInvoice->id ?? null,
            'account' => $accountId,
        ]);
    }
}
