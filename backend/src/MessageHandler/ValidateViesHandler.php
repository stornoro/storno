<?php

namespace App\MessageHandler;

use App\Entity\Client;
use App\Message\ValidateViesMessage;
use App\Service\Vies\ViesService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
class ValidateViesHandler
{
    private const MAX_ATTEMPTS = 3;
    private const RETRY_DELAY_MS = 30_000; // 30 seconds between retries

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ViesService $viesService,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(ValidateViesMessage $message): void
    {
        $client = $this->entityManager->find(Client::class, Uuid::fromString($message->clientId));
        if (!$client) {
            return;
        }

        // Already validated — skip
        if ($client->isViesValid() !== null) {
            return;
        }

        $vatCode = $client->getVatCode();
        if (!$vatCode) {
            return;
        }

        $parsed = $this->viesService->parseVatCode($vatCode);
        if (!$parsed) {
            return;
        }

        $result = $this->viesService->validate($parsed['countryCode'], $parsed['vatNumber']);

        if ($result === null) {
            // API failure — retry if under max attempts
            if ($message->attempt < self::MAX_ATTEMPTS) {
                $this->logger->info('VIES validation failed, scheduling retry', [
                    'clientId' => $message->clientId,
                    'attempt' => $message->attempt,
                ]);
                $this->messageBus->dispatch(
                    new ValidateViesMessage($message->clientId, $message->attempt + 1),
                    [new DelayStamp(self::RETRY_DELAY_MS)],
                );
            } else {
                $this->logger->warning('VIES validation failed after max retries', [
                    'clientId' => $message->clientId,
                ]);
            }
            return;
        }

        $client->setViesValid($result['valid']);
        $client->setViesValidatedAt(new \DateTimeImmutable());
        $client->setViesName($result['name']);

        if ($result['valid']) {
            $client->setIsVatPayer(true);
        }

        $this->entityManager->flush();

        $this->logger->info('VIES validation completed', [
            'clientId' => $message->clientId,
            'valid' => $result['valid'],
            'name' => $result['name'],
        ]);
    }
}
