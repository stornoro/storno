<?php

declare(strict_types=1);

namespace App\Controller\Api\V1;

use App\Security\OrganizationContext;
use App\Security\Permission;
use App\Service\Search\GlobalSearchService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** One query over invoices, proformas, clients, suppliers and products (see GlobalSearchService). */
#[Route('/api/v1/search')]
class SearchController extends AbstractController
{
    /** The permission each group needs. A group the caller cannot read is left out, not refused. */
    private const TYPE_PERMISSIONS = [
        'invoices' => Permission::INVOICE_VIEW,
        'proformas' => Permission::INVOICE_VIEW,
        'clients' => Permission::CLIENT_VIEW,
        'suppliers' => Permission::CLIENT_VIEW,
        'products' => Permission::PRODUCT_VIEW,
    ];

    public function __construct(
        private readonly OrganizationContext $organizationContext,
        private readonly GlobalSearchService $search,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $company = $this->organizationContext->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        $query = trim((string) $request->query->get('q', ''));
        $requested = array_filter(array_map('trim', explode(',', (string) $request->query->get('types', ''))));
        $types = [];
        foreach (GlobalSearchService::TYPES as $type) {
            if ($requested !== [] && !in_array($type, $requested, true)) {
                continue;
            }
            if ($this->organizationContext->hasPermission(self::TYPE_PERMISSIONS[$type])) {
                $types[] = $type;
            }
        }

        $groups = mb_strlen($query) < GlobalSearchService::MIN_LENGTH
            ? array_fill_keys($types, [])
            : $this->search->search($company, $query, $types, $request->query->getInt('limit', GlobalSearchService::DEFAULT_LIMIT));

        return $this->json([
            'query' => $query,
            'groups' => $groups,
            'total' => array_sum(array_map('count', $groups)),
        ]);
    }
}
