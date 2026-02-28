<?php

namespace App\Events\Anaf;

use App\Entity\AnafToken;
use App\Entity\User;
use Symfony\Contracts\EventDispatcher\Event;

class TokenUpdatedEvent extends Event
{
    const NAME = 'token.updated';

    public function __construct(
        private readonly User $user,
        private readonly AnafToken $anafToken,
    ) {}

    public function getUser(): User
    {
        return $this->user;
    }

    public function getAnafToken(): AnafToken
    {
        return $this->anafToken;
    }
}
