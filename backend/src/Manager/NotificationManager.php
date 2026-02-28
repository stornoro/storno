<?php

namespace App\Manager;

use App\Entity\Notification;
use App\Entity\User;
use App\Enum\NotificationChannels;
use App\Manager\Trait\UserTrait;
use App\Repository\NotificationRepository;
use App\Repository\UserRepository;
use App\Utils\Functions;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

class NotificationManager
{
    use UserTrait;


    public function __construct(
        private readonly Security $security,
        private readonly NotificationRepository $notificationRepository,
        private readonly EntityManagerInterface $em
    ) {
        $this->user = $security->getUser();
    }

    /**
     * The markRead function marks all notifications as read for a given user.
     * 
     * @param User user The user parameter is an instance of the User class. It represents the user for
     * whom the notifications should be marked as read.
     */
    public function markRead()
    {
        $this->notificationRepository->markRead($this->user);
    }
    /**
     * The `notify` function sends a notification with a title and message to a specified channel, or
     * the general channel by default, for a given user.
     * 
     * @param string title A string representing the title of the notification.
     * @param string message The `` parameter is a string that represents the content of the
     * notification message. It can be any text or information that you want to send to the user.
     * @param $channel The "channel" parameter is an optional string that represents the notification
     * channel. If no channel is specified, it defaults to the "GENERAL" channel.
     * @param User user The "user" parameter is an instance of the "User" class. It represents the user
     * to whom the notification will be sent.
     */
    public function notify(string $title, string $message, ?string $channel = null)
    {
        $notification = (new Notification())
            ->setTitle($title)
            ->setMessage($message)
            ->setType($channel ?? NotificationChannels::GENERAL)
            ->setChannel($channel ?? NotificationChannels::GENERAL)
            ->setSentAt(new \DateTimeImmutable())
            ->setUser($this->user);

        $this->create($notification);
    }
    /**
     * The create function persists a notification object and flushes it to the database.
     * 
     * @param Notification notification The parameter "notification" is an instance of the
     * "Notification" class.
     */
    private function create(Notification $notification)
    {
        $this->em->persist($notification);
        $this->em->flush();
    }

    public function latest(int $offset = 0): ?array
    {
        $notifications = $this->notificationRepository->findLatest($offset, $this->user);

        if (false === $this->user->isProduction()) {
            $notification = (new Notification())
                ->setTitle('Modul Testare')
                ->setMessage('Sunteti in modul testare, facturile transmise nu afecteaza procesul fiscal real.')
                ->setChannel(NotificationChannels::WARNING)
                ->setFrom(NotificationChannels::NOTIFICATION_FROM_SYSTEM)
                // ->setLink('/api/connect/anaf')
                ->setSentAt($this->user->getCreatedAt());
            $notifications[] = $notification;
        }
        if ($this->user->getAnafTokens()->isEmpty()) {
            $notification = (new Notification())
                ->setTitle('ANAF Token')
                ->setMessage('Configureaza tokenul ANAF')
                ->setChannel(NotificationChannels::ERROR)
                ->setFrom(NotificationChannels::NOTIFICATION_FROM_SYSTEM)
                ->setLink('/api/connect/anaf')
                ->setSentAt($this->user->getCreatedAt());
            $notifications[] = $notification;
        }
        foreach ($this->user->getAnafTokens() as $anafToken) {
            if ($anafToken->getExpireAt() < new \DateTime('+10 days')) {
                $label = $anafToken->getLabel() ? sprintf(' (%s)', $anafToken->getLabel()) : '';
                $notification = (new Notification())
                    ->setTitle('ANAF Token')
                    ->setMessage(sprintf('Tokenul ANAF%s urmeaza sa expire in %s', $label, Functions::displayRelativeTime($anafToken->getExpireAt()->getTimestamp())))
                    ->setChannel(NotificationChannels::ERROR)
                    ->setFrom(NotificationChannels::NOTIFICATION_FROM_SYSTEM)
                    ->setLink('/api/connect/anaf')
                    ->setSentAt(new \DateTimeImmutable('midnight'));
                $notifications[] = $notification;
                break; // Show only one expiry warning
            }
        }

        return $notifications;
    }
}
