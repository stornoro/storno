<?php

namespace App\EventSubscriber;

use App\Service\Centrifugo\CentrifugoService;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;

/**
 * Auto-flushes the Centrifugo batch buffer at the end of each lifecycle:
 * - After HTTP response is sent (kernel.terminate)
 * - After a Messenger message is handled
 * - After a console command finishes
 */
class CentrifugoFlushSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly CentrifugoService $centrifugo,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::TERMINATE => 'onFlush',
            ConsoleEvents::TERMINATE => 'onFlush',
            WorkerMessageHandledEvent::class => 'onFlush',
        ];
    }

    public function onFlush(): void
    {
        $this->centrifugo->flush();
    }
}
