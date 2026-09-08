<?php

namespace App\MessageHandler;

use App\Message\SendMetaConversionMessage;
use App\Service\Marketing\MetaConversionsApi;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class SendMetaConversionHandler
{
    public function __construct(
        private readonly MetaConversionsApi $conversionsApi,
    ) {}

    public function __invoke(SendMetaConversionMessage $message): void
    {
        if (!$this->conversionsApi->isEnabled()) {
            return;
        }

        $this->conversionsApi->send([
            'event_name' => $message->eventName,
            'event_id' => $message->eventId,
            'event_time' => $message->eventTime,
            'event_source_url' => $message->eventSourceUrl,
            'email' => $message->email,
            'external_id' => $message->externalId,
            'client_ip' => $message->clientIp,
            'client_user_agent' => $message->clientUserAgent,
            'fbc' => $message->fbc,
            'fbp' => $message->fbp,
        ]);
    }
}
