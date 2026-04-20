<?php

namespace App\MessageHandler;

use App\Message\SendPushNotificationMessage;
use App\Service\PushRelayService;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Exception\Messaging\InvalidMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class SendPushNotificationHandler
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly PushRelayService $pushRelayService,
        private readonly ?Messaging $messaging = null,
        private readonly ?string $firebaseCredentials = '',
    ) {}

    public function __invoke(SendPushNotificationMessage $message): void
    {
        $token = $message->getDeviceToken();

        // Path 1: Push relay (PUSH_RELAY_URL is set). Preferred — the relay
        // owns the Firebase service account key, so app instances don't need
        // FIREBASE_CREDENTIALS configured. Tried first so a half-configured
        // kreait Messaging service (autowired but missing creds) doesn't
        // intercept and throw 'Unable to determine the Firebase Project ID'.
        if ($this->pushRelayService->isEnabled()) {
            $this->pushRelayService->send(
                $token,
                $message->getTitle(),
                $message->getBody(),
                $message->getData(),
                $message->getBadge(),
            );
            return;
        }

        // Path 2: Direct FCM via kreait (only used when no relay is configured
        // — typical for self-hosted instances that have their own Firebase key).
        // The kreait bundle always autowires Messaging, even when credentials
        // are empty, and the service then throws "Unable to determine the
        // Firebase Project ID" on the first send. So also gate on the creds
        // env var and skip cleanly when it's unset.
        if ($this->messaging && !empty($this->firebaseCredentials)) {
            $this->sendViaFcm($message, $token);
            return;
        }

        // Path 3: Neither configured
        $this->logger->debug('Push notifications not configured (no Firebase credentials or push relay URL), skipping.');
    }

    private function sendViaFcm(SendPushNotificationMessage $message, string $token): void
    {
        try {
            $cloudMessage = CloudMessage::withTarget('token', $token)
                ->withNotification(Notification::create($message->getTitle(), $message->getBody()));

            if (!empty($message->getData())) {
                $cloudMessage = $cloudMessage->withData(array_map('strval', $message->getData()));
            }

            if ($message->getBadge() !== null) {
                $cloudMessage = $cloudMessage->withApnsConfig([
                    'payload' => ['aps' => ['badge' => $message->getBadge(), 'sound' => 'default']],
                ]);
            }

            $this->messaging->send($cloudMessage);
        } catch (NotFound|InvalidMessage $e) {
            $this->logger->warning('Invalid or expired FCM token.', [
                'token' => substr($token, 0, 20) . '...',
                'error' => $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send push notification via FCM.', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
