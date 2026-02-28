<?php

namespace App\Controller\Api\V1;

use App\Entity\Payment;
use App\Repository\InvoiceRepository;
use App\Repository\PaymentRepository;
use App\Security\OrganizationContext;
use App\Security\Permission;
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
}
