<?php

namespace App\EventSubscriber;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

class SchedulerAuditSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private LoggerInterface $schedulerLogger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerMessageReceivedEvent::class => 'onMessageReceived',
            WorkerMessageHandledEvent::class => 'onMessageHandled',
            WorkerMessageFailedEvent::class => 'onMessageFailed',
        ];
    }

    public function onMessageReceived(WorkerMessageReceivedEvent $event): void
    {
        $command = $this->extractCommand($event->getEnvelope());
        if ($command === null) {
            return;
        }

        $this->schedulerLogger->info('Scheduled task started', [
            'command' => $command,
            'transport' => $event->getReceiverName(),
        ]);
    }

    public function onMessageHandled(WorkerMessageHandledEvent $event): void
    {
        $command = $this->extractCommand($event->getEnvelope());
        if ($command === null) {
            return;
        }

        $this->schedulerLogger->info('Scheduled task completed', [
            'command' => $command,
            'transport' => $event->getReceiverName(),
        ]);
    }

    public function onMessageFailed(WorkerMessageFailedEvent $event): void
    {
        $command = $this->extractCommand($event->getEnvelope());
        if ($command === null) {
            return;
        }

        $this->schedulerLogger->error('Scheduled task FAILED', [
            'command' => $command,
            'transport' => $event->getReceiverName(),
            'error' => $event->getThrowable()->getMessage(),
            'will_retry' => $event->willRetry(),
        ]);
    }

    private function extractCommand(\Symfony\Component\Messenger\Envelope $envelope): ?string
    {
        // Only log messages coming from the scheduler transport
        $receivedStamp = $envelope->last(ReceivedStamp::class);
        if ($receivedStamp === null || !str_starts_with($receivedStamp->getTransportName(), 'scheduler')) {
            return null;
        }

        $message = $envelope->getMessage();
        if ($message instanceof RunCommandMessage) {
            return $message->input;
        }

        return $message::class;
    }
}
