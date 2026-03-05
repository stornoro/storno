<?php

namespace App\Controller\Api\V1;

use App\Entity\User;
use App\Message\ProcessTelemetryBatchMessage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/telemetry')]
class TelemetryController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
    ) {}

    #[Route('', methods: ['POST'])]
    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $data = json_decode($request->getContent(), true);
        if (!$data || !is_array($data['events'] ?? null) || empty($data['events'])) {
            return $this->json(['error' => 'Missing or empty events array.'], Response::HTTP_BAD_REQUEST);
        }

        if (count($data['events']) > 100) {
            return $this->json(['error' => 'Maximum 100 events per batch.'], Response::HTTP_BAD_REQUEST);
        }

        $companyId = $request->headers->get('X-Company');

        $this->messageBus->dispatch(new ProcessTelemetryBatchMessage(
            userId: $user->getId()->toRfc4122(),
            companyId: $companyId,
            events: $data['events'],
            platform: $data['platform'] ?? 'unknown',
            appVersion: $data['app_version'] ?? null,
        ));

        return $this->json(['status' => 'accepted'], Response::HTTP_ACCEPTED);
    }
}
