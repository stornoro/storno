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
    ) {}

    public function __invoke(SendPushNotificationMessage $message): void
    {
        $token = $message->getDeviceToken();

        // Path 1: Direct FCM via kreait (FIREBASE_CREDENTIALS is set)
        if ($this->messaging) {
            $this->sendViaFcm($message, $token);
            return;
        }

        // Path 2: Push relay (PUSH_RELAY_URL is set)
        if ($this->pushRelayService->isEnabled()) {
            $this->pushRelayService->send(
                $token,
                $message->getTitle(),
                $message->getBody(),
                $message->getData(),
            );
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
