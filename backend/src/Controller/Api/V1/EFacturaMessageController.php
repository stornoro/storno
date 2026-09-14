<?php

namespace App\Controller\Api\V1;

use App\Constants\Pagination;
use App\Entity\DocumentEvent;
use App\Enum\InvoiceDirection;
use App\Repository\EFacturaMessageRepository;
use App\Repository\InvoiceRepository;
use App\Security\OrganizationContext;
use App\Security\Permission;
use App\Service\Anaf\AnafTokenResolver;
use App\Service\Anaf\EFacturaClient;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1')]
class EFacturaMessageController extends AbstractController
{
    public function __construct(
        private readonly EFacturaMessageRepository $messageRepository,
        private readonly OrganizationContext $organizationContext,
        private readonly InvoiceRepository $invoiceRepository,
        private readonly AnafTokenResolver $tokenResolver,
        private readonly EFacturaClient $eFacturaClient,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Message to the issuer of a received e-Factura (SPV "RASP"): a dispute, a request for a
     * correction, a note that the invoice is not ours. ANAF delivers it to the seller's SPV
     * under the invoice's upload index; nothing changes on the invoice itself.
     */
    #[Route('/invoices/{uuid}/efactura-message', methods: ['POST'])]
    public function messageToIssuer(string $uuid, Request $request): JsonResponse
    {
        $invoice = $this->invoiceRepository->findWithDetails($uuid);
        if (!$invoice || !$this->organizationContext->ownsCompany($invoice->getCompany())) {
            return $this->json(['error' => 'Invoice not found.'], Response::HTTP_NOT_FOUND);
        }
        $this->denyAccessUnlessGranted('INVOICE_ISSUE', $invoice);

        if ($invoice->getDirection() !== InvoiceDirection::INCOMING) {
            return $this->json(['error' => 'Only a received invoice can be answered.', 'code' => 'NOT_RECEIVED_INVOICE'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $uploadIndex = $invoice->getAnafUploadId();
        if (!$uploadIndex) {
            return $this->json(['error' => 'The invoice has no ANAF upload index, so ANAF cannot route a message to its issuer.', 'code' => 'NO_UPLOAD_INDEX'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $data = json_decode($request->getContent(), true) ?: [];
        $message = trim((string) ($data['message'] ?? ''));
        if ($message === '' || mb_strlen($message) > EFacturaClient::RASP_MAX_LENGTH) {
            return $this->json(['error' => sprintf('message is required (max %d characters).', EFacturaClient::RASP_MAX_LENGTH), 'code' => 'VALIDATION_ERROR'], Response::HTTP_BAD_REQUEST);
        }

        $company = $invoice->getCompany();
        $token = $this->tokenResolver->resolve($company);
        if (!$token) {
            return $this->json(['error' => 'No valid ANAF token for this company.', 'code' => 'ANAF_TOKEN_REQUIRED'], Response::HTTP_CONFLICT);
        }

        try {
            $result = $this->eFacturaClient->sendMessageToIssuer($uploadIndex, $message, (string) $company->getCif(), $token);
        } catch (\Throwable $e) {
            $this->logger->warning('RASP message failed: {error}', ['error' => $e->getMessage(), 'invoiceId' => (string) $invoice->getId()]);
            return $this->json(['error' => 'ANAF did not accept the message: ' . $e->getMessage(), 'code' => 'ANAF_ERROR'], Response::HTTP_BAD_GATEWAY);
        }

        if (!$result->success) {
            return $this->json(['error' => $result->errorMessage ?: 'ANAF rejected the message.', 'code' => 'ANAF_REJECTED'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $event = new DocumentEvent();
        $event->setNewStatus($invoice->getStatus());
        $event->setMetadata([
            'action' => 'efactura_message_sent',
            'uploadIndex' => $uploadIndex,
            'messageIndex' => $result->uploadId,
            'message' => $message,
            'userId' => (string) $this->getUser()?->getId(),
        ]);
        $invoice->addEvent($event);
        $this->entityManager->flush();

        return $this->json([
            'success' => true,
            'uploadIndex' => $uploadIndex,
            'messageIndex' => $result->uploadId,
            'message' => $message,
        ]);
    }

    #[Route('/efactura-messages', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $company = $this->organizationContext->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::EFACTURA_VIEW)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $filters = $request->query->all();
        $page = $request->query->getInt('page', 1);
        $limit = Pagination::clamp($request->query->getInt('limit', Pagination::DEFAULT_LIMIT));

        $result = $this->messageRepository->findByCompanyPaginated($company, $filters, $page, $limit);

        return $this->json($result, context: ['groups' => ['efactura_message:list']]);
    }

    #[Route('/efactura-messages/{uuid}', methods: ['GET'])]
    public function show(string $uuid, Request $request): JsonResponse
    {
        $company = $this->organizationContext->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        $message = $this->messageRepository->find($uuid);
        if (!$message || $message->getCompany()?->getId()->toRfc4122() !== $company->getId()->toRfc4122()) {
            return $this->json(['error' => 'Message not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::EFACTURA_VIEW)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        return $this->json($message, context: ['groups' => ['efactura_message:detail']]);
    }
}
