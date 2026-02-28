<?php

namespace App\Controller\Api\V1;

use App\Constants\Pagination;
use App\Manager\ReceiptManager;
use App\Repository\EmailLogRepository;
use App\Security\OrganizationContext;
use App\Security\Permission;
use App\Service\ReceiptEmailService;
use App\Service\DocumentPdfService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1')]
class ReceiptController extends AbstractController
{
    public function __construct(
        private readonly ReceiptManager $receiptManager,
        private readonly OrganizationContext $organizationContext,
        private readonly DocumentPdfService $documentPdfService,
        private readonly LoggerInterface $logger,
        private readonly ReceiptEmailService $receiptEmailService,
        private readonly EmailLogRepository $emailLogRepository,
    ) {}

    #[Route('/receipts', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $company = $this->organizationContext->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::INVOICE_VIEW)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $filters = $request->query->all();
        $page = $request->query->getInt('page', 1);
        $limit = Pagination::clamp($request->query->getInt('limit', Pagination::DEFAULT_LIMIT));

        $result = $this->receiptManager->listByCompany($company, $filters, $page, $limit);

        return $this->json($result, context: ['groups' => ['receipt:list']]);
    }

    #[Route('/receipts/{uuid}', methods: ['GET'])]
    public function show(string $uuid): JsonResponse
    {
        $receipt = $this->receiptManager->find($uuid);
        if (!$receipt) {
            return $this->json(['error' => 'Receipt not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::INVOICE_VIEW)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        return $this->json($receipt, context: ['groups' => ['receipt:detail']]);
    }

    #[Route('/receipts', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $company = $this->organizationContext->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::INVOICE_CREATE)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Authentication required.'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);

        // Basic validation
        if (empty($data['lines']) || !is_array($data['lines'])) {
            return $this->json(['error' => 'At least one line is required.'], Response::HTTP_BAD_REQUEST);
        }

        foreach ($data['lines'] as $i => $line) {
            if (empty($line['description'])) {
                return $this->json(['error' => "Line $i: description is required."], Response::HTTP_BAD_REQUEST);
            }
            if (!isset($line['quantity']) || (float) $line['quantity'] <= 0) {
                return $this->json(['error' => "Line $i: quantity must be positive."], Response::HTTP_BAD_REQUEST);
            }
            if (!isset($line['unitPrice']) || (float) $line['unitPrice'] < 0) {
                return $this->json(['error' => "Line $i: unitPrice must be non-negative."], Response::HTTP_BAD_REQUEST);
            }
        }

        try {
            $receipt = $this->receiptManager->create($company, $data, $user);
        } catch (\Throwable $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return $this->json($receipt, Response::HTTP_CREATED, [], ['groups' => ['receipt:detail']]);
    }

    #[Route('/receipts/{uuid}', methods: ['PUT'])]
    public function update(string $uuid, Request $request): JsonResponse
    {
        $receipt = $this->receiptManager->find($uuid);
        if (!$receipt) {
            return $this->json(['error' => 'Receipt not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::INVOICE_EDIT)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Authentication required.'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);

        try {
            $receipt = $this->receiptManager->update($receipt, $data, $user);
        } catch (\DomainException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json($receipt, context: ['groups' => ['receipt:detail']]);
    }

    #[Route('/receipts/{uuid}', methods: ['DELETE'])]
    public function deleteReceipt(string $uuid): JsonResponse
    {
        $receipt = $this->receiptManager->find($uuid);
        if (!$receipt) {
            return $this->json(['error' => 'Receipt not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::INVOICE_DELETE)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $this->receiptManager->delete($receipt);
        } catch (\DomainException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/receipts/{uuid}/issue', methods: ['POST'])]
    public function issue(string $uuid): JsonResponse
    {
        $receipt = $this->receiptManager->find($uuid);
        if (!$receipt) {
            return $this->json(['error' => 'Receipt not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::INVOICE_ISSUE)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Authentication required.'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $this->receiptManager->issue($receipt, $user);
        } catch (\DomainException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json($receipt, context: ['groups' => ['receipt:detail']]);
    }

    #[Route('/receipts/{uuid}/cancel', methods: ['POST'])]
    public function cancel(string $uuid): JsonResponse
    {
        $receipt = $this->receiptManager->find($uuid);
        if (!$receipt) {
            return $this->json(['error' => 'Receipt not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::INVOICE_CANCEL)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $this->receiptManager->cancel($receipt);
        } catch (\DomainException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json($receipt, context: ['groups' => ['receipt:detail']]);
    }

    #[Route('/receipts/{uuid}/restore', methods: ['POST'])]
    public function restore(string $uuid): JsonResponse
    {
        $receipt = $this->receiptManager->find($uuid);
        if (!$receipt) {
            return $this->json(['error' => 'Receipt not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::INVOICE_EDIT)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $this->receiptManager->restore($receipt);
        } catch (\DomainException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json($receipt, context: ['groups' => ['receipt:detail']]);
    }

    #[Route('/receipts/{uuid}/convert', methods: ['POST'])]
    public function convert(string $uuid, Request $request): JsonResponse
    {
        $company = $this->organizationContext->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::INVOICE_CREATE)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $receipt = $this->receiptManager->find($uuid);
        if (!$receipt) {
            return $this->json(['error' => 'Receipt not found.'], Response::HTTP_NOT_FOUND);
        }

        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Authentication required.'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $invoice = $this->receiptManager->convertToInvoice($receipt, $company, $user);
        } catch (\DomainException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json($invoice, Response::HTTP_CREATED, [], ['groups' => ['invoice:detail']]);
    }

    #[Route('/receipts/{uuid}/pdf', methods: ['GET'])]
    public function downloadPdf(string $uuid): Response
    {
        $receipt = $this->receiptManager->find($uuid);
        if (!$receipt) {
            return $this->json(['error' => 'Receipt not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::INVOICE_VIEW)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $pdfContent = $this->documentPdfService->generateReceiptPdf($receipt);
        } catch (\Throwable $e) {
            $this->logger->error('Receipt PDF generation failed', ['error' => $e->getMessage()]);
            return $this->json(['error' => 'PDF generation failed.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new Response($pdfContent, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('inline; filename="bon-%s.pdf"', $receipt->getNumber()),
        ]);
    }

    #[Route('/receipts/{uuid}/email', methods: ['POST'])]
    public function sendEmail(string $uuid, Request $request): JsonResponse
    {
        $receipt = $this->receiptManager->find($uuid);
        if (!$receipt) {
            return $this->json(['error' => 'Receipt not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::INVOICE_SEND)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Authentication required.'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);

        $to = $data['to'] ?? null;
        if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return $this->json(['error' => 'A valid recipient email address is required.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $emailLog = $this->receiptEmailService->send(
                receipt: $receipt,
                to: $to,
                subject: $data['subject'] ?? null,
                body: $data['body'] ?? null,
                cc: $data['cc'] ?? null,
                bcc: $data['bcc'] ?? null,
                sentBy: $user,
            );
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->json($emailLog, Response::HTTP_OK, [], ['groups' => ['email_log:detail']]);
    }

    #[Route('/receipts/{uuid}/email-defaults', methods: ['GET'])]
    public function emailDefaults(string $uuid): JsonResponse
    {
        $receipt = $this->receiptManager->find($uuid);
        if (!$receipt) {
            return $this->json(['error' => 'Receipt not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::INVOICE_VIEW)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        return $this->json([
            'to' => $this->receiptEmailService->getDefaultRecipient($receipt),
            'subject' => $this->receiptEmailService->getDefaultSubject($receipt),
            'body' => $this->receiptEmailService->getDefaultBody($receipt),
        ]);
    }

    #[Route('/receipts/{uuid}/emails', methods: ['GET'])]
    public function emailHistory(string $uuid): JsonResponse
    {
        $receipt = $this->receiptManager->find($uuid);
        if (!$receipt) {
            return $this->json(['error' => 'Receipt not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::INVOICE_VIEW)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $logs = $this->emailLogRepository->findByReceipt($receipt);

        return $this->json($logs, context: ['groups' => ['email_log:detail']]);
    }
}
