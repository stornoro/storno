<?php

namespace App\Manager\Trait;

use App\Entity\User;

trait UserTrait
{
    protected ?User $user = null;
    /**
     * Get the value of user
     *
     * @return User
     */
    public function getUser(): User
    {
        return $this->user;
    }
}
