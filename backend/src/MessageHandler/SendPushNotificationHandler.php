<?php

namespace App\MessageHandler;

use App\Entity\Notification as NotificationEntity;
use App\Message\SendPushNotificationMessage;
use App\Repository\UserDeviceRepository;
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
        private readonly UserDeviceRepository $userDeviceRepository,
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
                $message->isSilent(),
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

        // Dead-token cleanup: when FCM tells us the token will never deliver
        // again (app uninstalled, token rotated), drop the UserDevice row so
        // we stop dispatching to it. Without this every future notification
        // round burns a relay round-trip + an FCM call per dead device.
        if ($this->isDeadTokenError($error)) {
            $this->forgetDevice($token);
        }

        $this->recordResult($message->getNotificationId(), $error);
    }

    private function isDeadTokenError(?string $error): bool
    {
        if ($error === null) {
            return false;
        }
        // Relay surfaces a stable code; the kreait direct path uses the
        // 'fcm_invalid_token:' prefix from sendViaFcm() below.
        return $error === PushRelayService::ERROR_TOKEN_UNREGISTERED
            || str_starts_with($error, 'fcm_invalid_token:');
    }

    private function forgetDevice(string $token): void
    {
        $device = $this->userDeviceRepository->findByToken($token);
        if ($device === null) {
            return;
        }
        $this->logger->info('Removing dead push token after FCM unregistered response.', [
            'userDeviceId' => (string) $device->getId(),
            'platform' => $device->getPlatform(),
            'tokenPrefix' => substr($token, 0, 20) . '...',
        ]);
        $this->entityManager->remove($device);
        $this->entityManager->flush();
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
            $cloudMessage = CloudMessage::withTarget('token', $token);

            // Silent badge-update pushes have no banner — skip withNotification
            // and use a content-available APNs payload so iOS just refreshes
            // the app icon badge.
            if ($message->isSilent()) {
                $cloudMessage = $cloudMessage->withApnsConfig([
                    'headers' => [
                        'apns-push-type' => 'background',
                        'apns-priority' => '5',
                    ],
                    'payload' => ['aps' => [
                        'content-available' => 1,
                        'badge' => $message->getBadge() ?? 0,
                    ]],
                ]);
            } else {
                $cloudMessage = $cloudMessage->withNotification(Notification::create($message->getTitle(), $message->getBody()));

                if ($message->getBadge() !== null) {
                    $cloudMessage = $cloudMessage->withApnsConfig([
                        'payload' => ['aps' => ['badge' => $message->getBadge(), 'sound' => 'default']],
                    ]);
                }
            }

            if (!empty($message->getData())) {
                // FCM requires all data values to be strings — encode nested
                // arrays/objects as JSON so the mobile client can parse them.
                $cloudMessage = $cloudMessage->withData(PushRelayService::stringifyData($message->getData()));
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
