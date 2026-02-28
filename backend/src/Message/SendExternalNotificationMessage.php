<?php

namespace App\Message;

class SendExternalNotificationMessage
{
    public function __construct(
        private readonly string $userId,
        private readonly string $title,
        private readonly string $message,
        private readonly string $eventType,
        private readonly array $data = [],
    ) {}

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }

    public function getData(): array
    {
        return $this->data;
    }
}
