<?php

namespace App\Message;

class SendPushNotificationMessage
{
    public function __construct(
        private readonly string $deviceToken,
        private readonly string $title,
        private readonly string $body,
        private readonly array $data = [],
        private readonly ?int $badge = null,
        private readonly ?string $notificationId = null,
        // Silent (background) push — APNs-only — used to update the iOS app
        // icon badge after the user marks notifications as read. Builds the
        // FCM payload without a `notification` block + apns content-available
        // so iOS just updates the badge without showing a banner.
        private readonly bool $silent = false,
    ) {}

    public function getDeviceToken(): string
    {
        return $this->deviceToken;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function getBadge(): ?int
    {
        return $this->badge;
    }

    public function getNotificationId(): ?string
    {
        return $this->notificationId;
    }

    public function isSilent(): bool
    {
        return $this->silent;
    }
}
