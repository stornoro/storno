<?php

namespace App\MessageHandler;

use App\Entity\TelemetryEvent;
use App\Message\ProcessTelemetryBatchMessage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
class ProcessTelemetryBatchHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(ProcessTelemetryBatchMessage $message): void
    {
        $userId = Uuid::fromString($message->userId);
        $companyId = $message->companyId ? Uuid::fromString($message->companyId) : null;

        foreach ($message->events as $eventData) {
            $event = new TelemetryEvent();
            $event->setUserId($userId);
            $event->setCompanyId($companyId);
            $event->setEvent($eventData['event'] ?? 'unknown');
            $event->setProperties($eventData['properties'] ?? []);
            $event->setPlatform($message->platform);
            $event->setAppVersion($message->appVersion);

            if (!empty($eventData['timestamp'])) {
                try {
                    $event->setCreatedAt(new \DateTimeImmutable($eventData['timestamp']));
                } catch (\Exception) {
                    // Keep default createdAt
                }
            }

            $this->entityManager->persist($event);
        }

        $this->entityManager->flush();

        $this->logger->debug('Processed telemetry batch', [
            'userId' => $message->userId,
            'eventCount' => count($message->events),
        ]);
    }
}
