<?php

namespace App\Controller\Api\V1;

use App\Security\OrganizationContext;
use App\Security\Permission;
use App\Service\Partner\PartnerVerificationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Bulk registry check of the company's partners (clients and suppliers).
 */
#[Route('/api/v1/partners')]
class PartnerVerificationController extends AbstractController
{
    public function __construct(
        private readonly OrganizationContext $organizationContext,
        private readonly PartnerVerificationService $partnerVerification,
    ) {}

    /**
     * Re-check every client and supplier not verified in the last `days` days
     * (default 30; 0 = everyone). Returns how many were checked, how many changed
     * and how many could not be checked because a registry did not answer.
     */
    #[Route('/verify-all', methods: ['POST'])]
    public function verifyAll(Request $request): JsonResponse
    {
        $company = $this->organizationContext->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->organizationContext->hasPermission(Permission::CLIENT_EDIT)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $days = $data['days'] ?? $request->query->get('days');
        $days = $days === null || $days === '' ? PartnerVerificationService::STALE_AFTER_DAYS : max(0, (int) $days);
        $since = new \DateTimeImmutable(sprintf('-%d days', $days));

        $counts = $this->partnerVerification->verifyAll($company, $since);

        return $this->json($counts + ['since' => $since->format('c')]);
    }
}
