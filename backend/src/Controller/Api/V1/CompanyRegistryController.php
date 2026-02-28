<?php

namespace App\Controller\Api\V1;

use App\Constants\Pagination;
use App\Service\CompanyRegistryService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/company-registry')]
class CompanyRegistryController extends AbstractController
{
    public function __construct(
        private readonly CompanyRegistryService $registryService,
    ) {}

    #[Route('/search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        $query = trim((string) $request->query->get('q', ''));
        $limit = Pagination::clamp((int) ($request->query->get('limit', Pagination::DEFAULT_LIMIT)));

        if (mb_strlen($query) < 2) {
            return $this->json(['data' => []], Response::HTTP_OK, $this->cacheHeaders());
        }

        try {
            $results = $this->registryService->search($query, $limit);
        } catch (\Throwable) {
            // Graceful degradation: return empty if SQLite unavailable or query fails
            $results = [];
        }

        return $this->json(['data' => $results], Response::HTTP_OK, $this->cacheHeaders());
    }

    #[Route('/cities', methods: ['GET'])]
    public function cities(Request $request): JsonResponse
    {
        $county = trim((string) $request->query->get('county', ''));
        $search = trim((string) $request->query->get('q', ''));

        if ($county === '') {
            return $this->json(['data' => []], Response::HTTP_OK, $this->cacheHeaders());
        }

        try {
            $results = $this->registryService->getCities($county, $search);
        } catch (\Throwable) {
            $results = [];
        }

        return $this->json(['data' => $results], Response::HTTP_OK, $this->cacheHeaders());
    }

    private function cacheHeaders(): array
    {
        return [
            'Cache-Control' => 'public, max-age=300',
        ];
    }
}
