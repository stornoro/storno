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
     * Batch lookup: maps Stripe invoice IDs to their linked Storno invoices
     * (or null), scoped to the token's company.
     *
     * Used by the subscription viewport: the UI extension lists the
     * subscription's billing cycles client-side (it alone holds merchant Stripe
     * credentials) and posts the resulting invoice IDs here to discover which
     * cycles already have an e-Factura. The backend never calls Stripe — a
     * Marketplace install is not a Connect connected account, so server-side
     * Stripe calls for merchant data fail.
     */
    #[Route('/invoices/links', name: 'stripe_app_invoice_links', methods: ['POST'])]
    public function invoiceLinks(Request $request): JsonResponse
    {
        $appToken = $this->resolveAppToken($request);
        if (!$appToken) {
            return $this->unauthorized();
        }

        $data = json_decode($request->getContent(), true);
        $stripeInvoiceIds = $data['stripeInvoiceIds'] ?? [];
        if (!is_array($stripeInvoiceIds)) {
            $stripeInvoiceIds = [];
        }

        $links = [];
        foreach ($stripeInvoiceIds as $stripeInvoiceId) {
            if (!is_string($stripeInvoiceId) || $stripeInvoiceId === '') {
                continue;
            }
            $invoice = $this->invoiceRepository->findOneBy([
                'idempotencyKey' => 'stripe_app_' . $stripeInvoiceId,
                'company' => $appToken->getCompany(),
            ]);
            $links[$stripeInvoiceId] = $invoice ? $this->serializeInvoice($invoice) : null;
        }

        return $this->json(['links' => $links]);
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

        // The UI extension holds the merchant Stripe credentials, so it sends the
        // parent Stripe invoice id and the refund amount directly. The backend
        // resolves via Stripe only as a legacy fallback (which fails for
        // Marketplace installs — a platform key can't read a non-connected
        // account), so a missing parent invoice id is the normal modern path.
        $data = json_decode($request->getContent(), true) ?? [];
        $stripeInvoiceId = $data['stripeInvoiceId'] ?? null;
        $refundAmount = isset($data['refundAmount']) && is_numeric($data['refundAmount'])
            ? (float) $data['refundAmount']
            : null;

        if (!$stripeInvoiceId) {
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
            if ($refundAmount === null) {
                $refundAmount = $refund->amount / 100;
            }
        }

        if (!$stripeInvoiceId) {
            return $this->json([
                'error' => 'invalid_request',
                'message' => 'Charge is not linked to a Stripe invoice',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Find the parent Storno invoice (the e-Factura issued for the original Stripe invoice)
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

        // Reverse the original invoice (negative quantities), in its own series.
        // A partial Stripe refund reverses a proportional part of the original.
        $parentTotal = abs((float) $parentInvoice->getTotal());
        $ratio = null;
        if ($refundAmount !== null && $parentTotal > 0 && $refundAmount + 0.005 < $parentTotal) {
            $ratio = bcdiv((string) $refundAmount, (string) $parentTotal, 6);
        }

        try {
            $storno = $this->invoiceManager->createStorno(
                $parentInvoice,
                $appToken->getUser(),
                $ratio,
                $idempotencyKey,
            );

            if ($appToken->isAutoMode()) {
                try {
                    $this->invoiceManager->issue($storno, $appToken->getUser());
                    $this->invoiceManager->submitToAnaf($storno, $appToken->getUser());
                } catch (\Exception $e) {
                    $this->logger->warning('Stripe App: auto-submit storno failed', [
                        'stornoId' => $storno->getId()->toRfc4122(),
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return $this->json($this->serializeInvoice($storno), Response::HTTP_CREATED);
        } catch (\Exception $e) {
            $this->logger->error('Stripe App: storno creation failed', [
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

        // The UI extension posts the full Stripe invoice object (fetched
        // client-side with merchant credentials). Fall back to the legacy
        // server-side fetch only when it's absent — see createFromStripe().
        $data = json_decode($request->getContent(), true) ?? [];
        $stripeInvoice = $data['stripeInvoice'] ?? null;

        try {
            $invoice = is_array($stripeInvoice)
                ? $invoiceService->createFromStripeInvoice($appToken, $stripeInvoice)
                : $invoiceService->createFromStripeInvoiceId($appToken, $stripeInvoiceId);

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
