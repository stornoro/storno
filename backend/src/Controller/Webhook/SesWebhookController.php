<?php

namespace App\Controller\Webhook;

use App\Service\SesEventProcessor;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class SesWebhookController extends AbstractController
{
    public function __construct(
        private readonly SesEventProcessor $sesEventProcessor,
        private readonly LoggerInterface $logger,
        private readonly HttpClientInterface $httpClient,
    ) {}

    #[Route('/webhook/ses', name: 'webhook_ses', methods: ['POST'])]
    public function handleSesWebhook(Request $request): Response
    {
        $body = $request->getContent();
        $payload = json_decode($body, true);

        if (!$payload || !isset($payload['Type'])) {
            $this->logger->warning('SES webhook: invalid payload received');
            return new JsonResponse(['error' => 'Invalid payload'], Response::HTTP_BAD_REQUEST);
        }

        // Validate SNS Topic ARN (basic security check)
        $topicArn = $payload['TopicArn'] ?? '';
        if ($topicArn && !str_starts_with($topicArn, 'arn:aws:sns:')) {
            $this->logger->warning('SES webhook: invalid TopicArn: ' . $topicArn);
            return new JsonResponse(['error' => 'Invalid TopicArn'], Response::HTTP_FORBIDDEN);
        }

        return match ($payload['Type']) {
            'SubscriptionConfirmation' => $this->handleSubscriptionConfirmation($payload),
            'Notification' => $this->handleNotification($payload),
            'UnsubscribeConfirmation' => new JsonResponse(['status' => 'ok']),
            default => new JsonResponse(['error' => 'Unknown type'], Response::HTTP_BAD_REQUEST),
        };
    }

    private function handleSubscriptionConfirmation(array $payload): JsonResponse
    {
        $subscribeUrl = $payload['SubscribeURL'] ?? null;
        if (!$subscribeUrl) {
            $this->logger->warning('SES webhook: SubscriptionConfirmation without SubscribeURL');
            return new JsonResponse(['error' => 'No SubscribeURL'], Response::HTTP_BAD_REQUEST);
        }

        // Auto-confirm by fetching the SubscribeURL
        try {
            $this->httpClient->request('GET', $subscribeUrl);
            $this->logger->info('SES webhook: subscription confirmed for topic ' . ($payload['TopicArn'] ?? 'unknown'));
        } catch (\Throwable $e) {
            $this->logger->error('SES webhook: failed to confirm subscription: ' . $e->getMessage());
            return new JsonResponse(['error' => 'Failed to confirm'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new JsonResponse(['status' => 'subscription_confirmed']);
    }

    private function handleNotification(array $payload): JsonResponse
    {
        try {
            $this->sesEventProcessor->process($payload);
        } catch (\Throwable $e) {
            $this->logger->error('SES webhook: error processing notification: ' . $e->getMessage());
            // Return 200 to prevent SNS retries on processing errors
            return new JsonResponse(['status' => 'error_logged']);
        }

        return new JsonResponse(['status' => 'processed']);
    }
}
