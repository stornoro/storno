<?php

namespace App\Controller\Api\V1;

use App\Constants\Pagination;
use App\Entity\DeliveryNote;
use App\Manager\DeliveryNoteManager;
use App\Repository\EmailLogRepository;
use App\Repository\ProformaInvoiceRepository;
use App\Security\OrganizationContext;
use App\Security\Permission;
use App\Service\Anaf\ETransportValidator;
use App\Service\Anaf\ETransportXmlGenerator;
use App\Service\DeliveryNoteEmailService;
use App\Service\DocumentPdfService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1')]
class DeliveryNoteController extends AbstractController
{
    public function __construct(
        private readonly DeliveryNoteManager $deliveryNoteManager,
        private readonly OrganizationContext $organizationContext,
        private readonly DocumentPdfService $documentPdfService,
        private readonly LoggerInterface $logger,
        private readonly ProformaInvoiceRepository $proformaInvoiceRepository,
        private readonly DeliveryNoteEmailService $deliveryNoteEmailService,
        private readonly EmailLogRepository $emailLogRepository,
        private readonly ETransportValidator $eTransportValidator,
        private readonly ETransportXmlGenerator $eTransportXmlGenerator,
    ) {}

    #[Route('/delivery-notes', methods: ['GET'])]
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

        $result = $this->deliveryNoteManager->listByCompany($company, $filters, $page, $limit);

        return $this->json($result, context: ['groups' => ['delivery_note:list']]);
    }

    // IMPORTANT: Static-path routes must be defined BEFORE the {uuid} wildcard routes.

    #[Route('/delivery-notes/from-proforma', methods: ['POST'])]
    public function createFromProforma(Request $request): JsonResponse
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

        if (empty($data['proformaId'])) {
            return $this->json(['error' => 'proformaId is required.'], Response::HTTP_BAD_REQUEST);
        }

        $proforma = $this->proformaInvoiceRepository->findWithDetails($data['proformaId']);
        if (!$proforma) {
            return $this->json(['error' => 'Proforma invoice not found.'], Response::HTTP_NOT_FOUND);
        }

        try {
            $deliveryNote = $this->deliveryNoteManager->createFromProforma($proforma, $company, $user);
        } catch (\DomainException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json($deliveryNote, Response::HTTP_CREATED, [], ['groups' => ['delivery_note:detail']]);
    }

    #[Route('/delivery-notes/bulk-convert', methods: ['POST'])]
    public function bulkConvert(Request $request): JsonResponse
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

        if (empty($data['ids']) || !is_array($data['ids'])) {
            return $this->json(['error' => 'ids array is required.'], Response::HTTP_BAD_REQUEST);
        }

        $deliveryNotes = [];
        foreach ($data['ids'] as $id) {
            $dn = $this->deliveryNoteManager->find($id);
            if (!$dn) {
                return $this->json(['error' => sprintf('Delivery note %s not found.', $id)], Response::HTTP_NOT_FOUND);
            }
            $deliveryNotes[] = $dn;
        }

        try {
            $invoice = $this->deliveryNoteManager->bulkConvertToInvoice($deliveryNotes, $company, $user);
        } catch (\DomainException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json($invoice, Response::HTTP_CREATED, [], ['groups' => ['invoice:detail']]);
    }

    #[Route('/delivery-notes/{uuid}', methods: ['GET'])]
    public function show(string $uuid): JsonResponse
    {
        $deliveryNote = $this->deliveryNoteManager->find($uuid);
        if (!$deliveryNote) {
            return $this->json(['error' => 'Delivery note not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::INVOICE_VIEW)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        return $this->json($deliveryNote, context: ['groups' => ['delivery_note:detail']]);
    }

    #[Route('/delivery-notes', methods: ['POST'])]
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
            $deliveryNote = $this->deliveryNoteManager->create($company, $data, $user);
        } catch (\Throwable $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        $validation = $this->runETransportValidation($deliveryNote);

        return $this->json([
            'deliveryNote' => $deliveryNote,
            'validation' => $validation,
        ], Response::HTTP_CREATED, [], ['groups' => ['delivery_note:detail']]);
    }

    #[Route('/delivery-notes/{uuid}', methods: ['PUT'])]
    public function update(string $uuid, Request $request): JsonResponse
    {
        $deliveryNote = $this->deliveryNoteManager->find($uuid);
        if (!$deliveryNote) {
            return $this->json(['error' => 'Delivery note not found.'], Response::HTTP_NOT_FOUND);
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
            $deliveryNote = $this->deliveryNoteManager->update($deliveryNote, $data, $user);
        } catch (\DomainException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $validation = $this->runETransportValidation($deliveryNote);

        return $this->json([
            'deliveryNote' => $deliveryNote,
            'validation' => $validation,
        ], context: ['groups' => ['delivery_note:detail']]);
    }

    #[Route('/delivery-notes/{uuid}', methods: ['DELETE'])]
    public function deleteDeliveryNote(string $uuid): JsonResponse
    {
        $deliveryNote = $this->deliveryNoteManager->find($uuid);
        if (!$deliveryNote) {
            return $this->json(['error' => 'Delivery note not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::INVOICE_DELETE)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $this->deliveryNoteManager->delete($deliveryNote);
        } catch (\DomainException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/delivery-notes/{uuid}/issue', methods: ['POST'])]
    public function issue(string $uuid): JsonResponse
    {
        $deliveryNote = $this->deliveryNoteManager->find($uuid);
        if (!$deliveryNote) {
            return $this->json(['error' => 'Delivery note not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::INVOICE_ISSUE)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Authentication required.'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $this->deliveryNoteManager->issue($deliveryNote, $user);
        } catch (\DomainException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json($deliveryNote, context: ['groups' => ['delivery_note:detail']]);
    }

    #[Route('/delivery-notes/{uuid}/cancel', methods: ['POST'])]
    public function cancel(string $uuid): JsonResponse
    {
        $deliveryNote = $this->deliveryNoteManager->find($uuid);
        if (!$deliveryNote) {
            return $this->json(['error' => 'Delivery note not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::INVOICE_CANCEL)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $this->deliveryNoteManager->cancel($deliveryNote);
        } catch (\DomainException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json($deliveryNote, context: ['groups' => ['delivery_note:detail']]);
    }

    #[Route('/delivery-notes/{uuid}/restore', methods: ['POST'])]
    public function restore(string $uuid): JsonResponse
    {
        $deliveryNote = $this->deliveryNoteManager->find($uuid);
        if (!$deliveryNote) {
            return $this->json(['error' => 'Delivery note not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::INVOICE_EDIT)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $this->deliveryNoteManager->restore($deliveryNote);
        } catch (\DomainException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json($deliveryNote, context: ['groups' => ['delivery_note:detail']]);
    }

    #[Route('/delivery-notes/{uuid}/convert', methods: ['POST'])]
    public function convert(string $uuid, Request $request): JsonResponse
    {
        $company = $this->organizationContext->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::INVOICE_CREATE)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $deliveryNote = $this->deliveryNoteManager->find($uuid);
        if (!$deliveryNote) {
            return $this->json(['error' => 'Delivery note not found.'], Response::HTTP_NOT_FOUND);
        }

        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Authentication required.'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $invoice = $this->deliveryNoteManager->convertToInvoice($deliveryNote, $company, $user);
        } catch (\DomainException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json($invoice, Response::HTTP_CREATED, [], ['groups' => ['invoice:detail']]);
    }

    #[Route('/delivery-notes/{uuid}/validate-etransport', methods: ['POST'])]
    public function validateETransport(string $uuid): JsonResponse
    {
        $deliveryNote = $this->deliveryNoteManager->find($uuid);
        if (!$deliveryNote) {
            return $this->json(['error' => 'Delivery note not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::INVOICE_VIEW)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        // Phase 1: Entity validation
        $entityErrors = $this->eTransportValidator->validateEntity($deliveryNote);

        // Phase 2+3: Generate XML and validate against XSD + Schematron
        $xsdErrors = [];
        $schematronResult = null;
        if (empty($entityErrors)) {
            $xml = $this->eTransportXmlGenerator->generateNotification($deliveryNote);
            $xsdErrors = $this->eTransportValidator->validateXml($xml);

            if (empty($xsdErrors)) {
                $schematronResult = $this->eTransportValidator->validateSchematron($xml);
            }
        }

        $allErrors = $entityErrors;
        foreach ($xsdErrors as $err) {
            $allErrors[] = $err;
        }
        if ($schematronResult !== null && !$schematronResult->isValid) {
            foreach ($schematronResult->errors as $error) {
                $allErrors[] = [
                    'rule'     => $error->ruleId ?? 'SCH',
                    'message'  => $error->message,
                    'severity' => 'fatal',
                ];
            }
        }

        return $this->json([
            'valid' => empty($allErrors),
            'errors' => $allErrors,
            'warnings' => $schematronResult?->warnings ?? [],
            'schematronAvailable' => $this->eTransportValidator->isSchematronAvailable(),
        ]);
    }

    #[Route('/delivery-notes/{uuid}/submit-etransport', methods: ['POST'])]
    public function submitETransport(string $uuid): JsonResponse
    {
        $deliveryNote = $this->deliveryNoteManager->find($uuid);
        if (!$deliveryNote) {
            return $this->json(['error' => 'Delivery note not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::EFACTURA_SUBMIT)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Authentication required.'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $this->deliveryNoteManager->submitToETransport($deliveryNote, $user);
        } catch (\DomainException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json($deliveryNote, context: ['groups' => ['delivery_note:detail']]);
    }

    #[Route('/delivery-notes/{uuid}/storno', methods: ['POST'])]
    public function storno(string $uuid, Request $request): JsonResponse
    {
        $company = $this->organizationContext->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::INVOICE_REFUND)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $deliveryNote = $this->deliveryNoteManager->find($uuid);
        if (!$deliveryNote) {
            return $this->json(['error' => 'Delivery note not found.'], Response::HTTP_NOT_FOUND);
        }

        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Authentication required.'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $stornoNote = $this->deliveryNoteManager->storno($deliveryNote, $company, $user);
        } catch (\DomainException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json($stornoNote, Response::HTTP_CREATED, [], ['groups' => ['delivery_note:detail']]);
    }

    #[Route('/delivery-notes/{uuid}/pdf', methods: ['GET'])]
    public function downloadPdf(string $uuid, Request $request): Response
    {
        $deliveryNote = $this->deliveryNoteManager->find($uuid);
        if (!$deliveryNote) {
            return $this->json(['error' => 'Delivery note not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::INVOICE_VIEW)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $hideVat = $request->query->getBoolean('hideVat', false);
        $hidePrices = $request->query->getBoolean('hidePrices', false);

        try {
            $pdfContent = $this->documentPdfService->generateDeliveryNotePdf($deliveryNote, $hideVat, $hidePrices);
        } catch (\Throwable $e) {
            $this->logger->error('Delivery note PDF generation failed', ['error' => $e->getMessage()]);
            return $this->json(['error' => 'PDF generation failed.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new Response($pdfContent, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('inline; filename="aviz-%s.pdf"', $deliveryNote->getNumber()),
        ]);
    }

    #[Route('/delivery-notes/{uuid}/email', methods: ['POST'])]
    public function sendEmail(string $uuid, Request $request): JsonResponse
    {
        $deliveryNote = $this->deliveryNoteManager->find($uuid);
        if (!$deliveryNote) {
            return $this->json(['error' => 'Delivery note not found.'], Response::HTTP_NOT_FOUND);
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
            $emailLog = $this->deliveryNoteEmailService->send(
                deliveryNote: $deliveryNote,
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

    #[Route('/delivery-notes/{uuid}/email-defaults', methods: ['GET'])]
    public function emailDefaults(string $uuid): JsonResponse
    {
        $deliveryNote = $this->deliveryNoteManager->find($uuid);
        if (!$deliveryNote) {
            return $this->json(['error' => 'Delivery note not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::INVOICE_VIEW)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        return $this->json([
            'to' => $this->deliveryNoteEmailService->getDefaultRecipient($deliveryNote),
            'subject' => $this->deliveryNoteEmailService->getDefaultSubject($deliveryNote),
            'body' => $this->deliveryNoteEmailService->getDefaultBody($deliveryNote),
        ]);
    }

    #[Route('/delivery-notes/{uuid}/emails', methods: ['GET'])]
    public function emailHistory(string $uuid): JsonResponse
    {
        $deliveryNote = $this->deliveryNoteManager->find($uuid);
        if (!$deliveryNote) {
            return $this->json(['error' => 'Delivery note not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::INVOICE_VIEW)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $logs = $this->emailLogRepository->findByDeliveryNote($deliveryNote);

        return $this->json($logs, context: ['groups' => ['email_log:detail']]);
    }

    /**
     * Runs e-Transport validation (entity + XSD + Schematron) if the delivery note
     * has an e-Transport operation type set. Returns null otherwise.
     */
    private function runETransportValidation(DeliveryNote $deliveryNote): ?array
    {
        if ($deliveryNote->getEtransportOperationType() === null) {
            return null;
        }

        $entityErrors = $this->eTransportValidator->validateEntity($deliveryNote);

        $xsdErrors = [];
        $schematronResult = null;
        if (empty($entityErrors)) {
            $xml = $this->eTransportXmlGenerator->generateNotification($deliveryNote);
            $xsdErrors = $this->eTransportValidator->validateXml($xml);

            if (empty($xsdErrors)) {
                $schematronResult = $this->eTransportValidator->validateSchematron($xml);
            }
        }

        $allErrors = $entityErrors;
        foreach ($xsdErrors as $err) {
            $allErrors[] = $err;
        }
        if ($schematronResult !== null && !$schematronResult->isValid) {
            foreach ($schematronResult->errors as $error) {
                $allErrors[] = [
                    'rule'     => $error->ruleId ?? 'SCH',
                    'message'  => $error->message,
                    'severity' => 'fatal',
                ];
            }
        }

        return [
            'valid' => empty($allErrors),
            'errors' => $allErrors,
            'warnings' => $schematronResult?->warnings ?? [],
            'schematronAvailable' => $this->eTransportValidator->isSchematronAvailable(),
        ];
    }
}
