<?php

namespace App\Message;

/**
 * One Meta Conversions API event, queued so the HTTP call never sits in the
 * request that produced it. Carries only what the handler needs; the email is
 * hashed by the API client before it leaves the server.
 */
class SendMetaConversionMessage
{
    public function __construct(
        public readonly string $eventName,
        public readonly string $eventId,
        public readonly int $eventTime,
        public readonly ?string $email = null,
        public readonly ?string $externalId = null,
        public readonly ?string $clientIp = null,
        public readonly ?string $clientUserAgent = null,
        public readonly ?string $eventSourceUrl = null,
        public readonly ?string $fbc = null,
        public readonly ?string $fbp = null,
    ) {}
}
