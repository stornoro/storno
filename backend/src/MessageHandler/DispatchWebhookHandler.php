<?php

namespace App\MessageHandler;

use App\Entity\WebhookDelivery;
use App\Enum\WebhookDeliveryStatus;
use App\Message\DispatchWebhookMessage;
use App\Repository\WebhookDeliveryRepository;
use App\Repository\WebhookEndpointRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsMessageHandler]
class DispatchWebhookHandler
{
    private const MAX_ATTEMPTS = 3;
    private const RETRY_DELAYS_MS = [60_000, 300_000, 900_000]; // 1min, 5min, 15min

    public function __construct(
        private readonly WebhookEndpointRepository $endpointRepository,
        private readonly WebhookDeliveryRepository $deliveryRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly HttpClientInterface $httpClient,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(DispatchWebhookMessage $message): void
    {
        $endpoint = $this->endpointRepository->find($message->endpointId);
        if (!$endpoint || !$endpoint->isActive()) {
            return;
        }

        // Find or create delivery record
        $delivery = null;
        if ($message->deliveryId) {
            $delivery = $this->deliveryRepository->find($message->deliveryId);
        }

        if (!$delivery) {
            $delivery = new WebhookDelivery();
            $delivery->setEndpoint($endpoint);
            $delivery->setEventType($message->eventType);
            $delivery->setPayload($message->payload);
            $this->entityManager->persist($delivery);
        }

        $delivery->setAttempt($message->attempt);
        $delivery->setStatus(WebhookDeliveryStatus::PENDING);

        $jsonPayload = json_encode($message->payload, JSON_UNESCAPED_UNICODE);
        $signature = hash_hmac('sha256', $jsonPayload, $endpoint->getSecret());

        $startTime = hrtime(true);

        try {
            $response = $this->httpClient->request('POST', $endpoint->getUrl(), [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-Webhook-Signature' => $signature,
                    'X-Webhook-Event' => $message->eventType,
                    'X-Webhook-Id' => $delivery->getId()->toRfc4122(),
                    'User-Agent' => 'Storno-Webhook/1.0',
                ],
                'body' => $jsonPayload,
                'timeout' => 10,
            ]);

            $statusCode = $response->getStatusCode();
            $responseBody = $response->getContent(false);
            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

            $delivery->setResponseStatusCode($statusCode);
            $delivery->setResponseBody($responseBody);
            $delivery->setDurationMs($durationMs);

            if ($statusCode >= 200 && $statusCode < 300) {
                $delivery->setStatus(WebhookDeliveryStatus::SUCCESS);
                $delivery->setCompletedAt(new \DateTimeImmutable());
            } else {
                $this->handleFailure($delivery, $message, "HTTP $statusCode");
            }
        } catch (\Throwable $e) {
            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);
            $delivery->setDurationMs($durationMs);
            $this->handleFailure($delivery, $message, $e->getMessage());
        }

        $this->entityManager->flush();
    }

    private function handleFailure(WebhookDelivery $delivery, DispatchWebhookMessage $message, string $error): void
    {
        $delivery->setErrorMessage(substr($error, 0, 500));

        if ($message->attempt < self::MAX_ATTEMPTS) {
            $delayMs = self::RETRY_DELAYS_MS[$message->attempt - 1] ?? self::RETRY_DELAYS_MS[2];
            $nextRetryAt = new \DateTimeImmutable(sprintf('+%d seconds', (int) ($delayMs / 1000)));

            $delivery->setStatus(WebhookDeliveryStatus::RETRYING);
            $delivery->setNextRetryAt($nextRetryAt);

            $this->messageBus->dispatch(
                new DispatchWebhookMessage(
                    endpointId: $message->endpointId,
                    eventType: $message->eventType,
                    payload: $message->payload,
                    attempt: $message->attempt + 1,
                    deliveryId: $delivery->getId()->toRfc4122(),
                ),
                [new DelayStamp($delayMs)]
            );

            $this->logger->info('Webhook delivery retry scheduled', [
                'deliveryId' => $delivery->getId()->toRfc4122(),
                'attempt' => $message->attempt + 1,
                'delayMs' => $delayMs,
            ]);
        } else {
            $delivery->setStatus(WebhookDeliveryStatus::FAILED);
            $delivery->setCompletedAt(new \DateTimeImmutable());

            $this->logger->warning('Webhook delivery failed after max attempts', [
                'deliveryId' => $delivery->getId()->toRfc4122(),
                'endpointId' => $message->endpointId,
                'error' => $error,
            ]);
        }
    }
}
