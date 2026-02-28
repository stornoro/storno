<?php

namespace App\Message;

final readonly class SendInvitationEmailMessage
{
    public function __construct(
        public string $invitationId,
    ) {}
}
