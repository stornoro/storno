<?php

namespace App\MessageHandler;

use App\Message\CentrifugoPublishMessage;
use App\Service\Centrifugo\CentrifugoService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class CentrifugoPublishHandler
{
    public function __construct(
        private readonly CentrifugoService $centrifugo,
    ) {}

    public function __invoke(CentrifugoPublishMessage $message): void
    {
        // Use queue() so it gets batched with other events from the same lifecycle
        $this->centrifugo->queue($message->channel, $message->data);
    }
}
