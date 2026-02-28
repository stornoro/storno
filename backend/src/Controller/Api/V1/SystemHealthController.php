<?php

namespace App\Controller\Api\V1;

use App\Service\SystemHealthService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class SystemHealthController extends AbstractController
{
    public function __construct(
        private readonly SystemHealthService $healthService,
    ) {}

    #[Route('/api/v1/system/health', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        // If user is authenticated, return full diagnostics
        try {
            $user = $this->getUser();
        } catch (\Throwable) {
            $user = null;
        }

        if ($user) {
            $data = $this->healthService->runAllChecks();
            $status = $data['status'] === 'healthy'
                ? Response::HTTP_OK
                : Response::HTTP_SERVICE_UNAVAILABLE;

            return $this->json($data, $status);
        }

        // Unauthenticated: minimal status only
        $data = $this->healthService->getMinimalStatus();
        $status = $data['status'] === 'healthy'
            ? Response::HTTP_OK
            : Response::HTTP_SERVICE_UNAVAILABLE;

        return $this->json($data, $status);
    }
}
