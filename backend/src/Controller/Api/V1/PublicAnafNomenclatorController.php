<?php

namespace App\Controller\Api\V1;

use App\Service\Anaf\AnafNomenclatorService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * ANAF address / fiscal-office nomenclators, served from Storno's local mirror:
 * county, locality and street codes plus fiscal offices, as required by the
 * declaration XSDs (judet, cod_localit, cod_strada, ufisc). Public, cached,
 * rate limited per IP. Used by the declaration builders and the MCP tools.
 */
#[Route('/api/v1/public/anaf/nomenclator')]
class PublicAnafNomenclatorController extends AbstractController
{
    public function __construct(private readonly AnafNomenclatorService $nomenclator) {}

    #[Route('/judete', methods: ['GET'])]
    public function judete(Request $request, RateLimiterFactory $nomenclatorLimiter): JsonResponse
    {
        if ($limited = $this->limit($request, $nomenclatorLimiter)) {
            return $limited;
        }

        return $this->cached($this->json(['data' => $this->nomenclator->judete()]));
    }

    #[Route('/organe-fiscale/{judet}', methods: ['GET'])]
    public function organeFiscale(string $judet, Request $request, RateLimiterFactory $nomenclatorLimiter): JsonResponse
    {
        if ($limited = $this->limit($request, $nomenclatorLimiter)) {
            return $limited;
        }

        return $this->cached($this->json(['data' => $this->nomenclator->organeFiscale($judet)]));
    }

    #[Route('/localitati/{judet}', methods: ['GET'])]
    public function localitati(string $judet, Request $request, RateLimiterFactory $nomenclatorLimiter): JsonResponse
    {
        if ($limited = $this->limit($request, $nomenclatorLimiter)) {
            return $limited;
        }

        return $this->cached($this->json(['data' => $this->nomenclator->localitati($judet, $request->query->get('q'))]));
    }

    #[Route('/strazi/{judet}/{localitate}', methods: ['GET'])]
    public function strazi(string $judet, string $localitate, Request $request, RateLimiterFactory $nomenclatorLimiter): JsonResponse
    {
        if ($limited = $this->limit($request, $nomenclatorLimiter)) {
            return $limited;
        }
        $limit = min(200, max(1, $request->query->getInt('limit', 50)));

        return $this->cached($this->json(['data' => $this->nomenclator->strazi($judet, $localitate, $request->query->get('q'), $limit)]));
    }

    private function limit(Request $request, RateLimiterFactory $factory): ?JsonResponse
    {
        if ($factory->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            return null;
        }

        return $this->json(['error' => 'Prea multe cereri.', 'code' => 'RATE_LIMITED'], Response::HTTP_TOO_MANY_REQUESTS);
    }

    private function cached(JsonResponse $response): JsonResponse
    {
        $response->setPublic();
        $response->setMaxAge(3600);

        return $response;
    }
}
