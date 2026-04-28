<?php

declare(strict_types=1);

namespace App\Controller\Api\V1;

use App\Entity\Invoice;
use App\Entity\StripeAppToken;
use App\Enum\DocumentStatus;
use App\Enum\DocumentType;
use App\Manager\InvoiceManager;
use App\Repository\InvoiceRepository;
use App\Repository\StripeAppTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Stripe\StripeClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Data endpoints for the Stripe Dashboard extension.
 *
 * All routes require X-Stripe-App-Token; authentication is handled by
 * StripeAppTokenAuthenticator which overwrites X-Company with the token's
 * bound company, so no endpoint here may read X-Company directly.
 */
#[Route('/api/v1/stripe-app')]
class StripeAppDataController extends AbstractController
{
    public function __construct(
        private readonly StripeAppTokenRepository $tokenRepository,
        private readonly InvoiceRepository $invoiceRepository,
        private readonly InvoiceManager $invoiceManager,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly string $stripeSecretKey,
    ) {}

    /**
     * Returns the Storno invoice(s) linked to a Stripe invoice ID.
     * Used by the stripe.dashboard.invoice.detail viewport.
     */
    #[Route('/invoices-by-stripe/{stripeInvoiceId}', name: 'stripe_app_invoices_by_stripe', methods: ['GET'])]
    public function invoicesByStripeId(string $stripeInvoiceId, Request $request): JsonResponse
    {
        $appToken = $this->resolveAppToken($request);
        if (!$appToken) {
            return $this->unauthorized();
        }

        $idempotencyKey = 'stripe_app_' . $stripeInvoiceId;
        $invoice = $this->invoiceRepository->findOneBy([
            'idempotencyKey' => $idempotencyKey,
            'company' => $appToken->getCompany(),
        ]);

        if (!$invoice) {
            return $this->json(['invoice' => null]);
        }

        return $this->json(['invoice' => $this->serializeInvoice($invoice)]);
    }

    /**
     * Returns invoices linked to a Stripe subscription (all billing cycles).
     * The idempotency key for subscription invoices is stripe_app_{stripeInvoiceId}
     * for each cycle, so we search by subscription-scoped idempotency pattern
     * via the subscription metadata on the invoice.
     *
     * In practice: Stripe subscription invoices each have a unique invoice ID;
     * we accept a comma-separated list of Stripe invoice IDs as a query param
     * and return the matched Storno invoices.
     */
    #[Route('/subscriptions/{stripeSubscriptionId}/invoices', name: 'stripe_app_subscription_invoices', methods: ['GET'])]
    public function subscriptionInvoices(string $stripeSubscriptionId, Request $request): JsonResponse
    {
        $appToken = $this->resolveAppToken($request);
        if (!$appToken) {
            return $this->unauthorized();
        }

        // Fetch the subscription from Stripe to get its invoice list
        $stripe = $this->makeStripeClient($appToken);

        try {
            $stripeInvoices = $stripe->invoices->all([
                'subscription' => $stripeSubscriptionId,
                'limit' => 20,
            ], ['stripe_account' => $appToken->getStripeAccountId()]);
        } catch (\Exception $e) {
            $this->logger->warning('Stripe App: failed to fetch subscription invoices', [
                'subscriptionId' => $stripeSubscriptionId,
                'error' => $e->getMessage(),
            ]);

            return $this->json(['error' => 'not_found', 'message' => 'Subscription not found'], Response::HTTP_NOT_FOUND);
        }

        $results = [];
        foreach ($stripeInvoices->data as $stripeInvoice) {
            $idempotencyKey = 'stripe_app_' . $stripeInvoice->id;
            $invoice = $this->invoiceRepository->findOneBy([
                'idempotencyKey' => $idempotencyKey,
                'company' => $appToken->getCompany(),
            ]);

            $results[] = [
                'stripeInvoiceId' => $stripeInvoice->id,
                'stripePeriodStart' => $stripeInvoice->period_start ? date('Y-m-d', $stripeInvoice->period_start) : null,
                'stripePeriodEnd' => $stripeInvoice->period_end ? date('Y-m-d', $stripeInvoice->period_end) : null,
                'stripeAmount' => $stripeInvoice->total / 100,
                'stripeCurrency' => strtoupper($stripeInvoice->currency),
                'stripeStatus' => $stripeInvoice->status,
                'stornoInvoice' => $invoice ? $this->serializeInvoice($invoice) : null,
            ];
        }

        return $this->json(['invoices' => $results]);
    }

    /**
     * Returns the credit note (factura de storno) linked to a Stripe refund.
     * The idempotency key for refund credit notes is stripe_app_refund_{refundId}.
     */
    #[Route('/refunds/{stripeRefundId}', name: 'stripe_app_refund_detail', methods: ['GET'])]
    public function refundDetail(string $stripeRefundId, Request $request): JsonResponse
    {
        $appToken = $this->resolveAppToken($request);
        if (!$appToken) {
            return $this->unauthorized();
        }

        $idempotencyKey = 'stripe_app_refund_' . $stripeRefundId;
        $creditNote = $this->invoiceRepository->findOneBy([
            'idempotencyKey' => $idempotencyKey,
            'company' => $appToken->getCompany(),
        ]);

        return $this->json(['creditNote' => $creditNote ? $this->serializeInvoice($creditNote) : null]);
    }

    /**
     * Creates a Romanian credit note (factura de storno) from a Stripe refund.
     * Requires that the original Stripe invoice already has a linked Storno invoice.
     */
    #[Route('/refunds/{stripeRefundId}/create-credit-note', name: 'stripe_app_refund_create_credit_note', methods: ['POST'])]
    public function createCreditNoteFromRefund(string $stripeRefundId, Request $request): JsonResponse
    {
        $appToken = $this->resolveAppToken($request);
        if (!$appToken) {
            return $this->unauthorized();
        }

        // Idempotency: return existing if already created
        $idempotencyKey = 'stripe_app_refund_' . $stripeRefundId;
        $existing = $this->invoiceRepository->findOneBy([
            'idempotencyKey' => $idempotencyKey,
            'company' => $appToken->getCompany(),
        ]);
        if ($existing) {
            return $this->json($this->serializeInvoice($existing), Response::HTTP_OK);
        }

        $stripe = $this->makeStripeClient($appToken);

        try {
            $refund = $stripe->refunds->retrieve($stripeRefundId, [], [
                'stripe_account' => $appToken->getStripeAccountId(),
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'error' => 'not_found',
                'message' => 'Refund not found on Stripe',
            ], Response::HTTP_NOT_FOUND);
        }

        // The refund must be linked to a charge which in turn has an invoice
        $chargeId = $refund->charge;
        if (!$chargeId) {
            return $this->json([
                'error' => 'invalid_request',
                'message' => 'Refund is not linked to a charge',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $charge = $stripe->charges->retrieve((string) $chargeId, ['expand' => ['invoice']], [
                'stripe_account' => $appToken->getStripeAccountId(),
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'error' => 'not_found',
                'message' => 'Charge not found on Stripe',
            ], Response::HTTP_NOT_FOUND);
        }

        $stripeInvoiceId = is_object($charge->invoice) ? $charge->invoice->id : $charge->invoice;

        if (!$stripeInvoiceId) {
            return $this->json([
                'error' => 'invalid_request',
                'message' => 'Charge is not linked to a Stripe invoice',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Find the parent Storno invoice
        $parentKey = 'stripe_app_' . $stripeInvoiceId;
        $parentInvoice = $this->invoiceRepository->findOneBy([
            'idempotencyKey' => $parentKey,
            'company' => $appToken->getCompany(),
        ]);

        if (!$parentInvoice) {
            return $this->json([
                'error' => 'not_found',
                'message' => 'No Storno invoice found for the original Stripe invoice. Create the e-invoice first.',
            ], Response::HTTP_NOT_FOUND);
        }

        $refundAmount = $refund->amount / 100;
        $currency = strtoupper($refund->currency ?? $parentInvoice->getCurrency());

        $creditNoteData = [
            'documentType' => DocumentType::CREDIT_NOTE->value,
            'parentDocumentId' => $parentInvoice->getId()->toRfc4122(),
            'idempotencyKey' => $idempotencyKey,
            'currency' => $currency,
            'lines' => [
                [
                    'description' => 'Storno - Rambursare ' . $stripeRefundId,
                    'quantity' => 1,
                    'unitPrice' => (string) round(-abs($refundAmount) / 1.19, 2),
                    'vatRate' => '19',
                    'unitOfMeasure' => 'buc',
                ],
            ],
        ];

        if ($parentInvoice->getClient()) {
            $creditNoteData['clientId'] = $parentInvoice->getClient()->getId()->toRfc4122();
        } else {
            $creditNoteData['receiverName'] = $parentInvoice->getReceiverName() ?? 'Client';
            $creditNoteData['receiverCif'] = $parentInvoice->getReceiverCif();
        }

        try {
            $creditNote = $this->invoiceManager->create($appToken->getCompany(), $creditNoteData, $appToken->getUser());

            if ($appToken->isAutoMode()) {
                try {
                    $this->invoiceManager->issue($creditNote, $appToken->getUser());
                    $this->invoiceManager->submitToAnaf($creditNote, $appToken->getUser());
                } catch (\Exception $e) {
                    $this->logger->warning('Stripe App: auto-submit credit note failed', [
                        'creditNoteId' => $creditNote->getId()->toRfc4122(),
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return $this->json($this->serializeInvoice($creditNote), Response::HTTP_CREATED);
        } catch (\Exception $e) {
            $this->logger->error('Stripe App: credit note creation failed', [
                'stripeRefundId' => $stripeRefundId,
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'error' => 'creation_failed',
                'message' => $e->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    /**
     * Creates a Storno invoice from a Stripe subscription invoice cycle.
     * Delegates to the existing invoice service but wraps it for subscription context.
     */
    #[Route('/subscriptions/{stripeSubscriptionId}/invoices/{stripeInvoiceId}/create', name: 'stripe_app_subscription_invoice_create', methods: ['POST'])]
    public function createSubscriptionInvoice(
        string $stripeSubscriptionId,
        string $stripeInvoiceId,
        Request $request,
        \App\Service\StripeAppInvoiceService $invoiceService,
    ): JsonResponse {
        $appToken = $this->resolveAppToken($request);
        if (!$appToken) {
            return $this->unauthorized();
        }

        // Idempotency: return if already exists
        $idempotencyKey = 'stripe_app_' . $stripeInvoiceId;
        $existing = $this->invoiceRepository->findOneBy([
            'idempotencyKey' => $idempotencyKey,
            'company' => $appToken->getCompany(),
        ]);
        if ($existing) {
            return $this->json($this->serializeInvoice($existing), Response::HTTP_OK);
        }

        try {
            $invoice = $invoiceService->createFromStripeInvoiceId($appToken, $stripeInvoiceId);

            return $this->json($this->serializeInvoice($invoice), Response::HTTP_CREATED);
        } catch (\Exception $e) {
            $this->logger->error('Stripe App: subscription invoice creation failed', [
                'stripeInvoiceId' => $stripeInvoiceId,
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'error' => 'creation_failed',
                'message' => $e->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    private function serializeInvoice(Invoice $invoice): array
    {
        return [
            'id' => $invoice->getId()->toRfc4122(),
            'invoiceNumber' => $invoice->getNumber(),
            'issueDate' => $invoice->getIssueDate()?->format('Y-m-d'),
            'total' => $invoice->getTotal(),
            'currency' => $invoice->getCurrency(),
            'receiverName' => $invoice->getReceiverName(),
            'status' => $invoice->getStatus()->value,
            'documentType' => $invoice->getDocumentType()->value,
            'anafStatus' => $invoice->getAnafStatus(),
            'anafErrorMessage' => $invoice->getAnafErrorMessage(),
            'parentDocumentId' => $invoice->getParentDocumentId(),
        ];
    }

    private function makeStripeClient(StripeAppToken $token): StripeClient
    {
        return new StripeClient($this->stripeSecretKey);
    }

    private function resolveAppToken(Request $request): ?StripeAppToken
    {
        $tokenValue = $request->headers->get('X-Stripe-App-Token');
        if (!$tokenValue) {
            return null;
        }

        return $this->tokenRepository->findValidByAccessToken($tokenValue);
    }

    private function unauthorized(): JsonResponse
    {
        return $this->json([
            'error' => 'unauthorized',
            'message' => 'Session expired. Please reconnect from Settings.',
        ], Response::HTTP_UNAUTHORIZED);
    }
}
