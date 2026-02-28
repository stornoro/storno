<?php

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Mailer\Event\SentMessageEvent;

#[AsEventListener(event: SentMessageEvent::class)]
class SesMessageIdListener
{
    private ?string $lastMessageId = null;

    public function __invoke(SentMessageEvent $event): void
    {
        $this->lastMessageId = $event->getMessage()->getMessageId();
    }

    public function getLastMessageId(): ?string
    {
        return $this->lastMessageId;
    }

    public function reset(): void
    {
        $this->lastMessageId = null;
    }
}
