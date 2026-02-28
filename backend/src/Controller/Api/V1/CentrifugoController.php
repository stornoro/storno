<?php

namespace App\Controller\Api\V1;

use App\Security\OrganizationContext;
use App\Service\Centrifugo\CentrifugoService;
use App\Service\LicenseManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/centrifugo')]
class CentrifugoController extends AbstractController
{
    public function __construct(
        private readonly CentrifugoService $centrifugo,
        private readonly OrganizationContext $organizationContext,
        private readonly LicenseManager $licenseManager,
    ) {}

    #[Route('/connection-token', methods: ['POST'])]
    public function connectionToken(): JsonResponse
    {
        $user = $this->getUser();

        $org = $this->organizationContext->getOrganization();
        if ($org && !$this->licenseManager->canReceiveRealtimeNotifications($org)) {
            return $this->json([
                'error' => 'Realtime notifications are not available on your plan.',
                'code' => 'PLAN_LIMIT',
            ], Response::HTTP_PAYMENT_REQUIRED);
        }

        $token = $this->centrifugo->generateConnectionToken(
            (string) $user->getId(),
            time() + 3600,
            ['email' => $user->getEmail()],
        );

        return $this->json(['token' => $token]);
    }

    #[Route('/subscription-token', methods: ['POST'])]
    public function subscriptionToken(Request $request): JsonResponse
    {
        $user = $this->getUser();
        $payload = json_decode($request->getContent(), true) ?? [];
        $channel = $payload['channel'] ?? '';

        if (!$channel) {
            return $this->json(['error' => 'Channel is required'], 400);
        }

        $token = $this->centrifugo->generateSubscriptionToken(
            (string) $user->getId(),
            $channel,
            time() + 3600,
        );

        return $this->json(['token' => $token]);
    }
}
