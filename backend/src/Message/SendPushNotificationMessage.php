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
}
