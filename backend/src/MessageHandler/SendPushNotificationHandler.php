<?php

namespace App\MessageHandler;

use App\Entity\Notification as NotificationEntity;
use App\Message\SendPushNotificationMessage;
use App\Service\PushRelayService;
use Doctrine\ORM\EntityManagerInterface;
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
        private readonly EntityManagerInterface $entityManager,
        private readonly ?Messaging $messaging = null,
        private readonly ?string $firebaseCredentials = '',
    ) {}

    public function __invoke(SendPushNotificationMessage $message): void
    {
        $token = $message->getDeviceToken();
        $error = null;

        // Path 1: Push relay (PUSH_RELAY_URL is set). Preferred — the relay
        // owns the Firebase service account key, so app instances don't need
        // FIREBASE_CREDENTIALS configured. Tried first so a half-configured
        // kreait Messaging service (autowired but missing creds) doesn't
        // intercept and throw 'Unable to determine the Firebase Project ID'.
        if ($this->pushRelayService->isEnabled()) {
            $error = $this->pushRelayService->send(
                $token,
                $message->getTitle(),
                $message->getBody(),
                $message->getData(),
                $message->getBadge(),
            );
        } elseif ($this->messaging && !empty($this->firebaseCredentials)) {
            // Path 2: Direct FCM via kreait (only used when no relay is configured
            // — typical for self-hosted instances that have their own Firebase key).
            // The kreait bundle always autowires Messaging, even when credentials
            // are empty, and the service then throws "Unable to determine the
            // Firebase Project ID" on the first send. So also gate on the creds
            // env var and skip cleanly when it's unset.
            $error = $this->sendViaFcm($message, $token);
        } else {
            // Path 3: Neither configured
            $this->logger->debug('Push notifications not configured (no Firebase credentials or push relay URL), skipping.');
            $error = 'not_configured';
        }

        $this->recordResult($message->getNotificationId(), $error);
    }

    /**
     * Mirror the push attempt onto the in-app `Notification` row so the bell
     * view (and admin) can show "delivered / failed: <reason>".
     *
     * One notification can fan out to multiple devices. Successful pushes
     * "win" — once `pushSentAt` is set, later device errors don't unset it,
     * but their error message is still saved so users see why some devices
     * didn't get it.
     */
    private function recordResult(?string $notificationId, ?string $error): void
    {
        if (!$notificationId) {
            return;
        }
        $notification = $this->entityManager->getRepository(NotificationEntity::class)->find($notificationId);
        if (!$notification) {
            return;
        }

        $notification->setPushAttempted(true);
        if ($error === null) {
            $notification->setPushSentAt(new \DateTimeImmutable());
            $notification->setPushError(null);
        } elseif ($notification->getPushSentAt() === null) {
            // Only overwrite the error when we don't already have a successful delivery.
            $notification->setPushError($error);
        }
        $this->entityManager->flush();
    }

    private function sendViaFcm(SendPushNotificationMessage $message, string $token): ?string
    {
        try {
            $cloudMessage = CloudMessage::withTarget('token', $token)
                ->withNotification(Notification::create($message->getTitle(), $message->getBody()));

            if (!empty($message->getData())) {
                // FCM requires all data values to be strings — encode nested
                // arrays/objects as JSON so the mobile client can parse them.
                $cloudMessage = $cloudMessage->withData(PushRelayService::stringifyData($message->getData()));
            }

            if ($message->getBadge() !== null) {
                $cloudMessage = $cloudMessage->withApnsConfig([
                    'payload' => ['aps' => ['badge' => $message->getBadge(), 'sound' => 'default']],
                ]);
            }

            $this->messaging->send($cloudMessage);
            return null;
        } catch (NotFound|InvalidMessage $e) {
            $this->logger->warning('Invalid or expired FCM token.', [
                'token' => substr($token, 0, 20) . '...',
                'error' => $e->getMessage(),
            ]);
            return 'fcm_invalid_token: ' . mb_substr($e->getMessage(), 0, 200);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send push notification via FCM.', [
                'error' => $e->getMessage(),
            ]);
            return mb_substr($e->getMessage(), 0, 200);
        }
    }
}
