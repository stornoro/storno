<?php

namespace App\Events\Anaf;

use App\Entity\User;
use Symfony\Contracts\EventDispatcher\Event;

class TokenDeletedEvent extends Event
{
    const NAME = 'token.deleted';

    public function __construct(
        private readonly User $user,
    ) {}

    public function getUser(): User
    {
        return $this->user;
    }
}
