<?php

namespace App\Controller\Api\V1;

use App\Constants\Pagination;
use App\Repository\EFacturaMessageRepository;
use App\Security\OrganizationContext;
use App\Security\Permission;
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
    ) {}

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
    public function show(string $uuid): JsonResponse
    {
        $message = $this->messageRepository->find($uuid);
        if (!$message) {
            return $this->json(['error' => 'Message not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::EFACTURA_VIEW)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        return $this->json($message, context: ['groups' => ['efactura_message:detail']]);
    }
}
