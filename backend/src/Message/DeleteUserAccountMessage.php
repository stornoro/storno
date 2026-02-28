<?php

namespace App\Message;

final readonly class DeleteUserAccountMessage
{
    public function __construct(
        public string $userId,
    ) {
    }
}
