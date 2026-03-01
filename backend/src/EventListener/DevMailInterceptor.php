<?php

namespace App\EventListener;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Email;

#[AsEventListener(event: MessageEvent::class, priority: 200)]
class DevMailInterceptor
{
    public function __construct(
        private readonly string $env,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(MessageEvent $event): void
    {
        if ($this->env === 'prod') {
            return;
        }

        $message = $event->getMessage();

        $to = '(unknown)';
        $subject = '(no subject)';

        if ($message instanceof Email) {
            $to = implode(', ', array_map(fn ($a) => $a->getAddress(), $message->getTo()));
            $subject = $message->getSubject() ?? $subject;
        }

        $this->logger->warning('DevMailInterceptor: suppressed email in "{env}" environment. To: {to}, Subject: {subject}', [
            'env' => $this->env,
            'to' => $to,
            'subject' => $subject,
        ]);

        $event->reject();
    }
}
