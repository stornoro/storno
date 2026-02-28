<?php

namespace App\Controller\Api\V1;

use App\Entity\ProformaInvoice;
use App\Manager\ProformaInvoiceManager;
use App\Constants\Pagination;
use App\Security\OrganizationContext;
use App\Security\Permission;
use App\Service\DocumentPdfService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1')]
class ProformaInvoiceController extends AbstractController
{
    public function __construct(
        private readonly ProformaInvoiceManager $proformaInvoiceManager,
        private readonly OrganizationContext $organizationContext,
        private readonly DocumentPdfService $documentPdfService,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('/proforma-invoices', methods: ['GET'])]
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

        $result = $this->proformaInvoiceManager->listByCompany($company, $filters, $page, $limit);

        return $this->json($result, context: ['groups' => ['proforma:list']]);
    }

    #[Route('/proforma-invoices/bulk-delete', methods: ['POST'])]
    public function bulkDelete(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $ids = $data['ids'] ?? [];

        if (!is_array($ids) || count($ids) === 0 || count($ids) > 100) {
            return $this->json(['error' => 'Provide between 1 and 100 IDs.'], Response::HTTP_BAD_REQUEST);
        }

        if (!$this->organizationContext->hasPermission(Permission::INVOICE_DELETE)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $deleted = 0;
        $errors = [];

        foreach ($ids as $id) {
            $proforma = $this->proformaInvoiceManager->find($id);
            if (!$proforma) {
                $errors[] = ['id' => $id, 'error' => 'Proforma not found.'];
                continue;
            }
            try {
                $this->proformaInvoiceManager->delete($proforma);
                $deleted++;
            } catch (\Throwable $e) {
                $errors[] = ['id' => $id, 'error' => $e->getMessage()];
            }
        }

        return $this->json(['deleted' => $deleted, 'errors' => $errors]);
    }

    #[Route('/proforma-invoices/{uuid}', methods: ['GET'])]
    public function show(string $uuid): JsonResponse
    {
        $proforma = $this->proformaInvoiceManager->find($uuid);
        if (!$proforma) {
            return $this->json(['error' => 'Proforma not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::INVOICE_VIEW)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        return $this->json($proforma, context: ['groups' => ['proforma:detail']]);
    }

    #[Route('/proforma-invoices', methods: ['POST'])]
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
            $proforma = $this->proformaInvoiceManager->create($company, $data, $user);
        } catch (\Throwable $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return $this->json($proforma, Response::HTTP_CREATED, [], ['groups' => ['proforma:detail']]);
    }

    #[Route('/proforma-invoices/{uuid}', methods: ['PUT'])]
    public function update(string $uuid, Request $request): JsonResponse
    {
        $proforma = $this->proformaInvoiceManager->find($uuid);
        if (!$proforma) {
            return $this->json(['error' => 'Proforma not found.'], Response::HTTP_NOT_FOUND);
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
            $proforma = $this->proformaInvoiceManager->update($proforma, $data, $user);
        } catch (\DomainException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json($proforma, context: ['groups' => ['proforma:detail']]);
    }

    #[Route('/proforma-invoices/{uuid}', methods: ['DELETE'])]
    public function deleteProforma(string $uuid): JsonResponse
    {
        $proforma = $this->proformaInvoiceManager->find($uuid);
        if (!$proforma) {
            return $this->json(['error' => 'Proforma not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::INVOICE_DELETE)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $this->proformaInvoiceManager->delete($proforma);
        } catch (\DomainException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/proforma-invoices/{uuid}/send', methods: ['POST'])]
    public function send(string $uuid): JsonResponse
    {
        $proforma = $this->proformaInvoiceManager->find($uuid);
        if (!$proforma) {
            return $this->json(['error' => 'Proforma not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::INVOICE_SEND)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $this->proformaInvoiceManager->send($proforma);
        } catch (\DomainException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json($proforma, context: ['groups' => ['proforma:detail']]);
    }

    #[Route('/proforma-invoices/{uuid}/accept', methods: ['POST'])]
    public function accept(string $uuid): JsonResponse
    {
        $proforma = $this->proformaInvoiceManager->find($uuid);
        if (!$proforma) {
            return $this->json(['error' => 'Proforma not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::INVOICE_EDIT)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $this->proformaInvoiceManager->accept($proforma);
        } catch (\DomainException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json($proforma, context: ['groups' => ['proforma:detail']]);
    }

    #[Route('/proforma-invoices/{uuid}/reject', methods: ['POST'])]
    public function reject(string $uuid): JsonResponse
    {
        $proforma = $this->proformaInvoiceManager->find($uuid);
        if (!$proforma) {
            return $this->json(['error' => 'Proforma not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::INVOICE_EDIT)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $this->proformaInvoiceManager->reject($proforma);
        } catch (\DomainException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json($proforma, context: ['groups' => ['proforma:detail']]);
    }

    #[Route('/proforma-invoices/{uuid}/cancel', methods: ['POST'])]
    public function cancel(string $uuid): JsonResponse
    {
        $proforma = $this->proformaInvoiceManager->find($uuid);
        if (!$proforma) {
            return $this->json(['error' => 'Proforma not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::INVOICE_CANCEL)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $this->proformaInvoiceManager->cancel($proforma);
        } catch (\DomainException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json($proforma, context: ['groups' => ['proforma:detail']]);
    }

    #[Route('/proforma-invoices/{uuid}/convert', methods: ['POST'])]
    public function convert(string $uuid, Request $request): JsonResponse
    {
        $company = $this->organizationContext->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::INVOICE_CREATE)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $proforma = $this->proformaInvoiceManager->find($uuid);
        if (!$proforma) {
            return $this->json(['error' => 'Proforma not found.'], Response::HTTP_NOT_FOUND);
        }

        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Authentication required.'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $invoice = $this->proformaInvoiceManager->convertToInvoice($proforma, $company, $user);
        } catch (\DomainException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json($invoice, Response::HTTP_CREATED, [], ['groups' => ['invoice:detail']]);
    }

    #[Route('/proforma-invoices/{uuid}/pdf', methods: ['GET'])]
    public function downloadPdf(string $uuid): Response
    {
        $proforma = $this->proformaInvoiceManager->find($uuid);
        if (!$proforma) {
            return $this->json(['error' => 'Proforma not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::INVOICE_VIEW)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $pdfContent = $this->documentPdfService->generateProformaPdf($proforma);
        } catch (\Throwable $e) {
            $this->logger->error('Proforma PDF generation failed', ['error' => $e->getMessage()]);
            return $this->json(['error' => 'PDF generation failed.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new Response($pdfContent, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('inline; filename="proforma-%s.pdf"', $proforma->getNumber()),
        ]);
    }
}
