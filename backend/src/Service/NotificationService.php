<?php

namespace App\Service;

use App\Entity\Notification;
use App\Entity\NotificationPreference;
use App\Entity\User;
use App\Message\SendExternalNotificationMessage;
use App\Service\Centrifugo\CentrifugoService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

class NotificationService
{
    private const EMAIL_ENABLED_BY_DEFAULT = [
        'invoice.rejected',
        'token.expiring_soon',
        'export_ready',
        'payment.received',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CentrifugoService $centrifugo,
        private readonly MessageBusInterface $messageBus,
    ) {}

    public function createNotification(User $user, string $type, string $title, string $message, array $data = []): Notification
    {
        $notification = new Notification();
        $notification->setUser($user);
        $notification->setType($type);
        $notification->setTitle($title);
        $notification->setMessage($message);
        $notification->setChannel('in_app');
        $notification->setData($data);
        $notification->setSentAt(new \DateTimeImmutable());

        $this->entityManager->persist($notification);
        $this->entityManager->flush();

        // Queue real-time socket push (batched via CentrifugoFlushSubscriber)
        $this->centrifugo->queue(
            'notifications:user_' . $user->getId(),
            [
                'id' => (string) $notification->getId(),
                'type' => $type,
                'channel' => 'in_app',
                'title' => $title,
                'message' => $message,
                'data' => $data,
                'isRead' => false,
                'sentAt' => $notification->getSentAt()->format('c'),
            ],
        );

        // Queue async external notification (Telegram, WhatsApp)
        $this->messageBus->dispatch(new SendExternalNotificationMessage(
            userId: (string) $user->getId(),
            title: $title,
            message: $message,
            eventType: $type,
            data: $data,
        ));

        return $notification;
    }

    public function markAsRead(Notification $notification): void
    {
        $notification->setIsRead(true);

        $this->entityManager->flush();
    }

    public function getUnreadCount(User $user): int
    {
        return $this->entityManager->getRepository(Notification::class)
            ->count([
                'user' => $user,
                'isRead' => false,
            ]);
    }

    public function getUserPreference(User $user, string $eventType): NotificationPreference
    {
        $preference = $this->entityManager->getRepository(NotificationPreference::class)
            ->findOneBy([
                'user' => $user,
                'eventType' => $eventType,
            ]);

        if ($preference === null) {
            $preference = new NotificationPreference();
            $preference->setUser($user);
            $preference->setEventType($eventType);

            if (\in_array($eventType, self::EMAIL_ENABLED_BY_DEFAULT, true)) {
                $preference->setEmailEnabled(true);
            }

            $this->entityManager->persist($preference);
            $this->entityManager->flush();
        }

        return $preference;
    }
}
