<?php

namespace App\EventSubscriber\Anaf;

use App\Enum\NotificationChannels;
use App\Events\Anaf\TokenCreatedEvent;
use App\Events\Anaf\TokenDeletedEvent;
use App\Events\Anaf\TokenUpdatedEvent;
use App\Manager\NotificationManager;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class TokenSubscriber implements EventSubscriberInterface
{
    use LoggerAwareTrait;

    public function __construct(LoggerInterface $eventsLogger, private readonly NotificationManager $notificationManager)
    {
        $this->setLogger($eventsLogger);
    }

    public static function getSubscribedEvents(): array
    {
        return [
            TokenCreatedEvent::NAME => 'onTokenCreated',
            TokenUpdatedEvent::NAME => 'onTokenUpdated',
            TokenDeletedEvent::NAME => 'onTokenDeleted',
        ];
    }

    public function onTokenCreated(TokenCreatedEvent $event): void
    {
        $user = $event->getUser();
        $anafToken = $event->getAnafToken();
        $label = $anafToken->getLabel() ? sprintf(' (%s)', $anafToken->getLabel()) : '';

        $this->notificationManager->notify('ANAF Token', 'Token-ul ANAF a fost creat.' . $label, NotificationChannels::GENERAL);

        $this->logger->info(
            sprintf('[%s] Generated ANAF Token%s: %s', $user->getEmail(), $label, $anafToken->getToken())
        );
    }

    public function onTokenUpdated(TokenUpdatedEvent $event): void
    {
        $user = $event->getUser();
        $anafToken = $event->getAnafToken();
        $label = $anafToken->getLabel() ? sprintf(' (%s)', $anafToken->getLabel()) : '';

        $this->notificationManager->notify('ANAF Token', 'Token-ul ANAF a fost actualizat.' . $label, NotificationChannels::GENERAL);

        $this->logger->info(
            sprintf('[%s] Updated ANAF Token%s: %s', $user->getEmail(), $label, $anafToken->getToken())
        );
    }

    public function onTokenDeleted(TokenDeletedEvent $event): void
    {
        $user = $event->getUser();

        $this->notificationManager->notify('ANAF Token', 'Token-ul ANAF a fost sters.', NotificationChannels::GENERAL);

        $this->logger->info(
            sprintf('[%s] Deleted ANAF Token', $user->getEmail())
        );
    }
}
