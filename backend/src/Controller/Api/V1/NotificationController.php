<?php

namespace App\Controller\Api\V1;

use App\Entity\Notification;
use App\Entity\User;
use App\Constants\Pagination;
use App\Message\SendPushNotificationMessage;
use App\Repository\NotificationRepository;
use App\Repository\UserDeviceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/api/v1/notifications')]
class NotificationController extends AbstractController
{
    public function __construct(
        private readonly NotificationRepository $notificationRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserDeviceRepository $userDeviceRepository,
        private readonly MessageBusInterface $bus,
    ) {}

    #[Route('', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $page = $request->query->getInt('page', 1);
        $limit = Pagination::clamp($request->query->getInt('limit', Pagination::DEFAULT_LIMIT));

        $result = $this->notificationRepository->findPaginated($user, $page, $limit);

        return $this->json([
            'data' => array_map(fn (Notification $n) => $this->serialize($n), $result['items']),
            'total' => $result['total'],
            'page' => $page,
            'limit' => $limit,
        ]);
    }

    #[Route('/unread-count', methods: ['GET'])]
    public function unreadCount(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->json([
            'count' => $this->notificationRepository->countUnread($user),
        ]);
    }

    #[Route('/read-all', methods: ['POST'])]
    public function markAllRead(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $this->notificationRepository->markRead($user);
        $this->refreshIosBadge($user);

        return $this->json(['status' => 'ok']);
    }

    #[Route('/{id}/read', methods: ['PATCH'])]
    public function markRead(string $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $notification = $this->notificationRepository->find(Uuid::fromString($id));
        if (!$notification || $notification->getUser()?->getId()->toRfc4122() !== $user->getId()->toRfc4122()) {
            return $this->json(['error' => 'Notification not found.'], Response::HTTP_NOT_FOUND);
        }

        $notification->setIsRead(true);
        $this->entityManager->flush();
        $this->refreshIosBadge($user);

        return $this->json($this->serialize($notification));
    }

    /**
     * Push the current unread count to the user's iOS devices as a silent
     * APNs notification. Without this, the Springboard app icon badge
     * sticks at whatever the last delivered push set it to — so a user who
     * just cleared their feed still sees "(2)" on the icon until a new push
     * arrives. We only target iOS devices because Android has no equivalent
     * concept that's reachable via FCM data messages reliably across OEMs.
     */
    private function refreshIosBadge(User $user): void
    {
        $devices = $this->userDeviceRepository->findBy(['user' => $user, 'platform' => 'ios']);
        if (empty($devices)) {
            return;
        }

        $unread = $this->notificationRepository->countUnread($user);

        foreach ($devices as $device) {
            $this->bus->dispatch(new SendPushNotificationMessage(
                deviceToken: $device->getToken(),
                title: '',
                body: '',
                data: ['type' => 'badge_update'],
                badge: $unread,
                silent: true,
            ));
        }
    }

    private function serialize(Notification $n): array
    {
        return [
            'id' => (string) $n->getId(),
            'type' => $n->getType(),
            'channel' => $n->getChannel(),
            'title' => $n->getTitle(),
            'message' => $n->getMessage(),
            'from' => $n->getFrom(),
            'link' => $n->getLink(),
            'data' => $n->getData(),
            'isRead' => $n->isRead(),
            'readAt' => $n->isRead() ? $n->getUpdatedAt()?->format('c') : null,
            'sentAt' => $n->getSentAt()?->format('c'),
            'createdAt' => $n->getCreatedAt()?->format('c'),
            'push' => [
                'attempted' => $n->isPushAttempted(),
                'sentAt' => $n->getPushSentAt()?->format('c'),
                'error' => $n->getPushError(),
                'skippedReason' => $n->getPushSkippedReason(),
            ],
        ];
    }
}
