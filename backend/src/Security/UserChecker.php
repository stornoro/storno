<?php

namespace App\Security;

use App\Entity\User;
use App\Security\Exception\AccountDeniedLoginException;
use App\Security\Exception\AccountMailNotConfirmedException;
use Symfony\Component\Security\Core\Exception\AccountExpiredException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

class UserChecker implements UserCheckerInterface
{
    /**
     * @throws AccountDeniedLoginException
     */
    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof User) {
            return;
        }


        if (false === $user->isActive()) {
            throw new AccountDeniedLoginException();
        }
    }

    /**
     * @throws AccountExpiredException
     * @throws AccountMailNotConfirmedException
     */
    public function checkPostAuth(UserInterface $user): void
    {
        if (!$user instanceof User) {
            return;
        }

        // if ($user->isExpired()) {
        //     throw new AccountExpiredException();
        // }

        // if ($user->isSuspended()) {
        //     throw new AccountSuspendedException();
        // }

        if (false == $user->isEmailVerified()) {
            throw new AccountMailNotConfirmedException();
        }
    }
}
