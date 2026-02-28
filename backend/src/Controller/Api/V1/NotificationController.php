<?php

namespace App\Controller\Api\V1;

use App\Entity\Notification;
use App\Entity\User;
use App\Constants\Pagination;
use App\Repository\NotificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/api/v1/notifications')]
class NotificationController extends AbstractController
{
    public function __construct(
        private readonly NotificationRepository $notificationRepository,
        private readonly EntityManagerInterface $entityManager,
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

        return $this->json($this->serialize($notification));
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
        ];
    }
}
