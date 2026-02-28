<?php

namespace App\Controller\Api\V1;

use App\Entity\User;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/notification-preferences')]
class NotificationPreferenceController extends AbstractController
{
    public const EVENT_TYPES = [
        'invoice.validated',
        'invoice.rejected',
        'invoice.due_soon',
        'invoice.due_today',
        'invoice.overdue',
        'sync.completed',
        'sync.error',
        'efactura.new_documents',
        'token.expiring_soon',
        'token.refresh_failed',
        'export_ready',
    ];

    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    #[Route('', methods: ['GET'])]
    public function index(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $data = [];
        foreach (self::EVENT_TYPES as $eventType) {
            $pref = $this->notificationService->getUserPreference($user, $eventType);
            $data[] = [
                'eventType' => $pref->getEventType(),
                'emailEnabled' => $pref->isEmailEnabled(),
                'inAppEnabled' => $pref->isInAppEnabled(),
                'pushEnabled' => $pref->isPushEnabled(),
                'whatsappEnabled' => $pref->isWhatsappEnabled(),
            ];
        }

        return $this->json(['data' => $data]);
    }

    #[Route('', methods: ['PUT'])]
    public function update(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $payload = json_decode($request->getContent(), true);
        $preferences = $payload['preferences'] ?? [];

        if (!is_array($preferences)) {
            return $this->json(['error' => 'Invalid payload.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        foreach ($preferences as $item) {
            $eventType = $item['eventType'] ?? null;
            if (!$eventType || !in_array($eventType, self::EVENT_TYPES, true)) {
                continue;
            }

            $pref = $this->notificationService->getUserPreference($user, $eventType);

            if (isset($item['emailEnabled'])) {
                $pref->setEmailEnabled((bool) $item['emailEnabled']);
            }
            if (isset($item['inAppEnabled'])) {
                $pref->setInAppEnabled((bool) $item['inAppEnabled']);
            }
            if (isset($item['pushEnabled'])) {
                $pref->setPushEnabled((bool) $item['pushEnabled']);
            }
            if (isset($item['whatsappEnabled'])) {
                $pref->setWhatsappEnabled((bool) $item['whatsappEnabled']);
            }
        }

        $this->entityManager->flush();

        // Return full updated list
        $data = [];
        foreach (self::EVENT_TYPES as $eventType) {
            $pref = $this->notificationService->getUserPreference($user, $eventType);
            $data[] = [
                'eventType' => $pref->getEventType(),
                'emailEnabled' => $pref->isEmailEnabled(),
                'inAppEnabled' => $pref->isInAppEnabled(),
                'pushEnabled' => $pref->isPushEnabled(),
                'whatsappEnabled' => $pref->isWhatsappEnabled(),
            ];
        }

        return $this->json(['data' => $data]);
    }
}
