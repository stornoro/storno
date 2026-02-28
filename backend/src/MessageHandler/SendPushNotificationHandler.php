<?php

namespace App\MessageHandler;

use App\Message\SendPushNotificationMessage;
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
        private readonly ?Messaging $messaging = null,
    ) {}

    public function __invoke(SendPushNotificationMessage $message): void
    {
        if (!$this->messaging) {
            $this->logger->debug('Firebase Messaging not configured, skipping push notification.');
            return;
        }

        try {
            $cloudMessage = CloudMessage::withTarget('token', $message->getDeviceToken())
                ->withNotification(Notification::create($message->getTitle(), $message->getBody()));

            if (!empty($message->getData())) {
                $cloudMessage = $cloudMessage->withData(array_map('strval', $message->getData()));
            }

            $this->messaging->send($cloudMessage);
        } catch (NotFound|InvalidMessage $e) {
            $this->logger->warning('Invalid or expired FCM token.', [
                'token' => substr($message->getDeviceToken(), 0, 20) . '...',
                'error' => $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send push notification.', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
