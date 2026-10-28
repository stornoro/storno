<?php

namespace App\Controller\Api\V1;

use App\Entity\Payment;
use App\Repository\InvoiceRepository;
use App\Repository\PaymentRepository;
use App\Security\OrganizationContext;
use App\Security\Permission;
use App\Repository\DocumentSeriesRepository;
use App\Service\LicenseManager;
use App\Service\PaymentReceiptService;
use App\Service\PaymentService;
use App\Service\Webhook\WebhookDispatcher;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/invoices/{uuid}/payments')]
class PaymentController extends AbstractController
{
    public function __construct(
        private readonly InvoiceRepository $invoiceRepository,
        private readonly PaymentRepository $paymentRepository,
        private readonly PaymentService $paymentService,
        private readonly WebhookDispatcher $webhookDispatcher,
        private readonly OrganizationContext $organizationContext,
        private readonly PaymentReceiptService $receipts,
        private readonly DocumentSeriesRepository $documentSeriesRepository,
        private readonly LicenseManager $licenseManager,
    ) {}

    #[Route('', methods: ['GET'])]
    public function list(string $uuid): JsonResponse
    {
        $invoice = $this->invoiceRepository->findWithDetails($uuid);
        if (!$invoice) {
            return $this->json(['error' => 'Invoice not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->denyAccessUnlessGranted('INVOICE_VIEW', $invoice);

        $payments = $this->paymentRepository->findByInvoice($invoice);

        return $this->json($payments, context: ['groups' => ['payment:list']]);
    }

    #[Route('', methods: ['POST'])]
    public function create(string $uuid, Request $request): JsonResponse
    {
        $invoice = $this->invoiceRepository->findWithDetails($uuid);
        if (!$invoice) {
            return $this->json(['error' => 'Invoice not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->denyAccessUnlessGranted('INVOICE_VIEW', $invoice);

        if (!$this->organizationContext->hasPermission(Permission::PAYMENT_CREATE)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);

        if (empty($data['amount'])) {
            return $this->json(['error' => 'Field "amount" is required.'], Response::HTTP_BAD_REQUEST);
        }

        if (empty($data['paymentMethod'])) {
            return $this->json(['error' => 'Field "paymentMethod" is required.'], Response::HTTP_BAD_REQUEST);
        }

        /** @var \App\Entity\User|null $user */
        $user = $this->getUser();

        try {
            $payment = $this->paymentService->recordPayment($invoice, $data, $user);
        } catch (\DomainException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $company = $invoice->getCompany();
        if ($company) {
            $this->webhookDispatcher->dispatchForCompany($company, 'payment.received', [
                'paymentId' => $payment->getId()->toRfc4122(),
                'invoiceId' => $invoice->getId()->toRfc4122(),
                'amount' => $payment->getAmount(),
                'paymentMethod' => $payment->getPaymentMethod(),
                'currency' => $invoice->getCurrency(),
            ]);
        }

        return $this->json($payment, Response::HTTP_CREATED, [], ['groups' => ['payment:detail']]);
    }

    #[Route('/{paymentId}', methods: ['DELETE'])]
    public function delete(string $uuid, string $paymentId): JsonResponse
    {
        $invoice = $this->invoiceRepository->findWithDetails($uuid);
        if (!$invoice) {
            return $this->json(['error' => 'Invoice not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->denyAccessUnlessGranted('INVOICE_VIEW', $invoice);

        if (!$this->organizationContext->hasPermission(Permission::PAYMENT_DELETE)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $payment = $this->paymentRepository->find($paymentId);
        if (!$payment || $payment->getInvoice()?->getId()?->toRfc4122() !== $invoice->getId()?->toRfc4122()) {
            return $this->json(['error' => 'Payment not found.'], Response::HTTP_NOT_FOUND);
        }

        /** @var \App\Entity\User|null $user */
        $user = $this->getUser();

        $this->paymentService->deletePayment($payment, $user);

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Issue the chitanță for a cash payment: a number from the receipt series (the company's
     * default, or `seriesId`) and a date (`date`, default the payment date). Documents the
     * payment that already exists — nothing is recorded twice. Idempotent.
     */
    #[Route('/{paymentId}/receipt', methods: ['POST'])]
    public function issueReceipt(string $uuid, string $paymentId, Request $request): JsonResponse
    {
        $invoice = $this->invoiceRepository->findWithDetails($uuid);
        if (!$invoice) {
            return $this->json(['error' => 'Invoice not found.'], Response::HTTP_NOT_FOUND);
        }
        $this->denyAccessUnlessGranted('INVOICE_VIEW', $invoice);
        if (!$this->organizationContext->hasPermission(Permission::PAYMENT_CREATE)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }
        $payment = $this->paymentRepository->find($paymentId);
        if (!$payment || $payment->getInvoice()?->getId()?->toRfc4122() !== $invoice->getId()?->toRfc4122()) {
            return $this->json(['error' => 'Payment not found.'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true) ?: [];
        $series = null;
        if (!empty($data['seriesId'])) {
            $series = $this->documentSeriesRepository->find($data['seriesId']);
            if (!$series || (string) $series->getCompany()?->getId() !== (string) $invoice->getCompany()?->getId()) {
                return $this->json(['error' => 'Series not found.'], Response::HTTP_NOT_FOUND);
            }
        }
        $date = null;
        if (!empty($data['date'])) {
            $date = \DateTime::createFromFormat('!Y-m-d', (string) $data['date']);
            if (!$date || $date->format('Y-m-d') !== $data['date']) {
                return $this->json(['error' => 'Field "date" must be YYYY-MM-DD.'], Response::HTTP_BAD_REQUEST);
            }
        }

        /** @var \App\Entity\User|null $user */
        $user = $this->getUser();
        try {
            $payment = $this->receipts->issue($payment, $series, $date, $user);
        } catch (\DomainException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json($payment, Response::HTTP_OK, [], ['groups' => ['payment:detail']]);
    }

    #[Route('/{paymentId}/receipt/pdf', methods: ['GET'])]
    public function receiptPdf(string $uuid, string $paymentId): Response
    {
        $invoice = $this->invoiceRepository->findWithDetails($uuid);
        if (!$invoice) {
            return $this->json(['error' => 'Invoice not found.'], Response::HTTP_NOT_FOUND);
        }
        $this->denyAccessUnlessGranted('INVOICE_VIEW', $invoice);
        $payment = $this->paymentRepository->find($paymentId);
        if (!$payment || $payment->getInvoice()?->getId()?->toRfc4122() !== $invoice->getId()?->toRfc4122()) {
            return $this->json(['error' => 'Payment not found.'], Response::HTTP_NOT_FOUND);
        }
        if (!$payment->hasReceipt()) {
            return $this->json(['error' => 'No receipt issued for this payment.'], Response::HTTP_NOT_FOUND);
        }
        $org = $invoice->getCompany()?->getOrganization();
        if ($org && !$this->licenseManager->canGeneratePdf($org)) {
            return $this->json(['error' => 'PDF generation is not available on your plan.', 'code' => 'PLAN_LIMIT'], Response::HTTP_PAYMENT_REQUIRED);
        }

        return new Response($this->receipts->pdf($payment), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('inline; filename="chitanta-%s.pdf"', $payment->getReceiptNumber()),
        ]);
    }
}
