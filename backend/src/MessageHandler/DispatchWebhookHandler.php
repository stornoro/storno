<?php

namespace App\MessageHandler;

use App\Entity\WebhookDelivery;
use App\Enum\WebhookDeliveryStatus;
use App\Message\DispatchWebhookMessage;
use App\Repository\WebhookDeliveryRepository;
use App\Repository\WebhookEndpointRepository;
use App\Service\Security\OutboundUrlPolicy;
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

    /** Only this many bytes of an endpoint's response are ever persisted. */
    private const MAX_STORED_RESPONSE_BODY = 200;

    private readonly ?string $webhookRelayUrl;
    private readonly ?string $webhookRelaySecret;

    public function __construct(
        private readonly WebhookEndpointRepository $endpointRepository,
        private readonly WebhookDeliveryRepository $deliveryRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly HttpClientInterface $httpClient,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
        private readonly OutboundUrlPolicy $outboundUrlPolicy,
        ?string $webhookRelayUrl = null,
        ?string $webhookRelaySecret = null,
    ) {
        $this->webhookRelayUrl = $webhookRelayUrl ?: null;
        $this->webhookRelaySecret = $webhookRelaySecret ?: null;
    }

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

        // Re-check the outbound policy right before sending: the URL was
        // validated on save, but DNS can be rebound afterwards and legacy rows
        // predate the policy. A blocked URL is a hard failure — no retries.
        try {
            $targetUrl = $this->outboundUrlPolicy->assertAllowed($endpoint->getUrl(), ['httpsOnly' => true, 'allowedPorts' => [443]]);
        } catch (\InvalidArgumentException) {
            $delivery->setStatus(WebhookDeliveryStatus::FAILED);
            $delivery->setErrorMessage('Endpoint URL is not allowed.');
            $delivery->setCompletedAt(new \DateTimeImmutable());
            $this->entityManager->flush();

            $this->logger->warning('Webhook delivery blocked by outbound URL policy', [
                'deliveryId' => $delivery->getId()->toRfc4122(),
                'endpointId' => $message->endpointId,
            ]);

            return;
        }

        $jsonPayload = json_encode($message->payload, JSON_UNESCAPED_UNICODE);
        $signature = hash_hmac('sha256', $jsonPayload, $endpoint->getSecret());

        $startTime = hrtime(true);

        $webhookHeaders = [
            'Content-Type' => 'application/json',
            'X-Webhook-Signature' => $signature,
            'X-Webhook-Event' => $message->eventType,
            'X-Webhook-Id' => $delivery->getId()->toRfc4122(),
            'User-Agent' => 'Storno-Webhook/1.0',
        ];

        try {
            if ($this->webhookRelayUrl && $this->webhookRelaySecret) {
                $relayBody = json_encode([
                    'target_url' => $targetUrl,
                    'method' => 'POST',
                    'headers' => $webhookHeaders,
                    'body' => $jsonPayload,
                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

                $relayResponse = $this->httpClient->request('POST', $this->webhookRelayUrl, [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Authorization' => 'Bearer ' . $this->webhookRelaySecret,
                    ],
                    'body' => $relayBody,
                    'timeout' => 15,
                    'max_duration' => 20,
                    'max_redirects' => 0,
                ]);

                $relayData = json_decode($relayResponse->getContent(false), true);
                $statusCode = $relayData['status'] ?? 0;
                $responseBody = $relayData['body'] ?? ($relayData['error'] ?? '');
            } else {
                $response = $this->httpClient->request('POST', $targetUrl, [
                    'headers' => $webhookHeaders,
                    'body' => $jsonPayload,
                    'timeout' => 10,
                    'max_duration' => 15,
                    'max_redirects' => 0,
                ]);

                $statusCode = $response->getStatusCode();
                $responseBody = $response->getContent(false);
            }

            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

            $delivery->setResponseStatusCode($statusCode);
            $delivery->setResponseBody(mb_substr((string) $responseBody, 0, self::MAX_STORED_RESPONSE_BODY));
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
            $this->logger->info('Webhook delivery attempt failed', [
                'deliveryId' => $delivery->getId()->toRfc4122(),
                'endpointId' => $message->endpointId,
                'attempt' => $message->attempt,
                'error' => $e->getMessage(),
            ]);
            $this->handleFailure($delivery, $message, 'Delivery failed.');
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
