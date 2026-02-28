<?php

namespace App\Controller\Api\V1;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1')]
class NcCodeController extends AbstractController
{
    private ?array $ncCodes = null;

    #[Route('/nc-codes', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        $search = mb_strtolower(trim($request->query->get('search', '')));
        $limit = min($request->query->getInt('limit', 30), 100);

        $codes = $this->loadCodes();

        if (!$search) {
            return $this->json(array_slice($codes, 0, $limit));
        }

        $results = [];
        foreach ($codes as $code) {
            if (str_contains($code['cod'], $search) || str_contains(mb_strtolower($code['denumire']), $search)) {
                $results[] = $code;
                if (count($results) >= $limit) {
                    break;
                }
            }
        }

        return $this->json($results);
    }

    private function loadCodes(): array
    {
        if ($this->ncCodes !== null) {
            return $this->ncCodes;
        }

        $path = dirname(__DIR__, 4) . '/data/nc_codes.json';
        if (!file_exists($path)) {
            return [];
        }

        $this->ncCodes = json_decode(file_get_contents($path), true) ?: [];

        return $this->ncCodes;
    }
}
