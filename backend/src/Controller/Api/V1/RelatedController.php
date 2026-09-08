<?php

declare(strict_types=1);

namespace App\Controller\Api\V1;

use App\Security\OrganizationContext;
use App\Service\Related\RelatedService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Everything in the company that is connected to one record (see RelatedService). */
#[Route('/api/v1/related')]
class RelatedController extends AbstractController
{
    public function __construct(
        private readonly OrganizationContext $organizationContext,
        private readonly RelatedService $related,
    ) {
    }

    #[Route('/types', methods: ['GET'])]
    public function types(): JsonResponse
    {
        return $this->json(['types' => RelatedService::TYPES]);
    }

    #[Route('/{type}/{uuid}', methods: ['GET'], requirements: ['type' => '[a-z_]+'])]
    public function show(string $type, string $uuid, Request $request): JsonResponse
    {
        $company = $this->organizationContext->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }
        if (!in_array($type, RelatedService::TYPES, true)) {
            return $this->json(['error' => 'Tip necunoscut. Disponibile: ' . implode(', ', RelatedService::TYPES), 'code' => 'UNKNOWN_TYPE'], Response::HTTP_NOT_FOUND);
        }
        $result = $this->related->forRecord($company, $type, $uuid);
        if ($result === null) {
            return $this->json(['error' => 'Record not found.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json($result);
    }
}
