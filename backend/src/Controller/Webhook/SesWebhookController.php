<?php

namespace App\Controller\Webhook;

use App\Service\SesEventProcessor;
use Aws\Sns\Message;
use Aws\Sns\MessageValidator;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class SesWebhookController extends AbstractController
{
    /** Only ever auto-confirm subscriptions against a real SNS endpoint. */
    private const SNS_HOST_PATTERN = '/^sns\.[a-z0-9-]+\.amazonaws\.com$/';

    private readonly MessageValidator $messageValidator;

    public function __construct(
        private readonly SesEventProcessor $sesEventProcessor,
        private readonly LoggerInterface $logger,
        private readonly HttpClientInterface $httpClient,
        #[Autowire(env: 'SES_SNS_TOPIC_ARN')]
        private readonly ?string $expectedTopicArn = null,
        ?MessageValidator $messageValidator = null,
    ) {
        $this->messageValidator = $messageValidator ?? new MessageValidator();
    }

    #[Route('/webhook/ses', name: 'webhook_ses', methods: ['POST'])]
    public function handleSesWebhook(Request $request): Response
    {
        $body = $request->getContent();
        $payload = json_decode($body, true);

        if (!$payload || !isset($payload['Type'])) {
            $this->logger->warning('SES webhook: invalid payload received');
            return new JsonResponse(['error' => 'Invalid payload'], Response::HTTP_BAD_REQUEST);
        }

        // Verify the SNS signature before trusting anything else in the payload
        try {
            $message = Message::fromJsonString($body);
            $this->messageValidator->validate($message);
        } catch (\Throwable $e) {
            $this->logger->warning('SES webhook: SNS message validation failed: ' . $e->getMessage());
            return new JsonResponse(['error' => 'Invalid signature'], Response::HTTP_FORBIDDEN);
        }

        // Validate SNS Topic ARN: exact match when configured, prefix check otherwise
        $topicArn = (string) ($payload['TopicArn'] ?? '');
        if (!$this->isTopicArnAllowed($topicArn)) {
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

    private function isTopicArnAllowed(string $topicArn): bool
    {
        if ($this->expectedTopicArn !== null && $this->expectedTopicArn !== '') {
            return hash_equals($this->expectedTopicArn, $topicArn);
        }

        return str_starts_with($topicArn, 'arn:aws:sns:');
    }

    private function handleSubscriptionConfirmation(array $payload): JsonResponse
    {
        $subscribeUrl = $payload['SubscribeURL'] ?? null;
        if (!$subscribeUrl || !is_string($subscribeUrl)) {
            $this->logger->warning('SES webhook: SubscriptionConfirmation without SubscribeURL');
            return new JsonResponse(['error' => 'No SubscribeURL'], Response::HTTP_BAD_REQUEST);
        }

        if (!$this->isSnsUrl($subscribeUrl)) {
            $this->logger->warning('SES webhook: refusing to fetch non-SNS SubscribeURL: ' . $subscribeUrl);
            return new JsonResponse(['error' => 'Invalid SubscribeURL'], Response::HTTP_BAD_REQUEST);
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

    private function isSnsUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        return $scheme === 'https'
            && !isset($parts['user']) && !isset($parts['pass'])
            && preg_match(self::SNS_HOST_PATTERN, $host) === 1;
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
