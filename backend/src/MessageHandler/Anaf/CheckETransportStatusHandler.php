<?php

namespace App\MessageHandler\Anaf;

use App\Entity\DeliveryNote;
use App\Message\Anaf\CheckETransportStatusMessage;
use App\Service\Anaf\AnafTokenResolver;
use App\Service\Anaf\ETransportClient;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final class CheckETransportStatusHandler
{
    private const DELAY_SCHEDULE_MS = [
        0 => 300_000,     // 5 minutes
        1 => 900_000,     // 15 minutes
        2 => 1_800_000,   // 30 minutes
        3 => 3_600_000,   // 1 hour
        4 => 7_200_000,   // 2 hours
    ];

    private const MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ETransportClient $eTransportClient,
        private readonly AnafTokenResolver $tokenResolver,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(CheckETransportStatusMessage $message): void
    {
        $note = $this->entityManager->getRepository(DeliveryNote::class)->find(
            Uuid::fromString($message->deliveryNoteId)
        );

        if ($note === null) {
            $this->logger->warning('CheckETransportStatusHandler: DeliveryNote not found.', [
                'deliveryNoteId' => $message->deliveryNoteId,
            ]);
            return;
        }

        $uploadId = $note->getEtransportUploadId();
        if ($uploadId === null) {
            $this->logger->warning('CheckETransportStatusHandler: No upload ID on delivery note.', [
                'deliveryNoteId' => $message->deliveryNoteId,
            ]);
            return;
        }

        $token = $this->tokenResolver->resolve($note->getCompany());
        if ($token === null) {
            $this->logger->error('CheckETransportStatusHandler: No ANAF token available.', [
                'deliveryNoteId' => $message->deliveryNoteId,
            ]);
            return;
        }

        $statusResponse = $this->eTransportClient->checkStatus($uploadId, $token);

        if ($statusResponse->isOk()) {
            $note->setEtransportUit($statusResponse->uit);
            $note->setEtransportStatus('ok');
            $note->setEtransportErrorMessage(null);
            $this->entityManager->flush();

            $this->logger->info('CheckETransportStatusHandler: UIT received.', [
                'deliveryNoteId' => $message->deliveryNoteId,
                'uit' => $statusResponse->uit,
            ]);
            return;
        }

        if ($statusResponse->isError()) {
            $note->setEtransportStatus('nok');
            $note->setEtransportErrorMessage($statusResponse->errorMessage);
            $this->entityManager->flush();

            $this->logger->error('CheckETransportStatusHandler: ANAF rejected.', [
                'deliveryNoteId' => $message->deliveryNoteId,
                'error' => $statusResponse->errorMessage,
            ]);
            return;
        }

        // Still pending — retry with exponential backoff
        $nextAttempt = $message->attempt + 1;
        if ($nextAttempt >= self::MAX_ATTEMPTS) {
            $note->setEtransportStatus('pending_timeout');
            $note->setEtransportErrorMessage('Numarul maxim de verificari a fost atins.');
            $this->entityManager->flush();
            return;
        }

        $delay = self::DELAY_SCHEDULE_MS[$message->attempt] ?? self::DELAY_SCHEDULE_MS[4];

        $this->messageBus->dispatch(
            new CheckETransportStatusMessage(
                deliveryNoteId: $message->deliveryNoteId,
                attempt: $nextAttempt,
            ),
            [new DelayStamp($delay)]
        );
    }
}
