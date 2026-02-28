<?php

namespace App\Security\Voter;

use App\Entity\ApiToken;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\User\UserInterface;

class ApiTokenVoter extends Voter
{
    public const EDIT = 'edit';
    public const VIEW = 'view';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::EDIT, self::VIEW])
            && $subject instanceof \App\Entity\ApiToken;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        // if the user is anonymous, do not grant access
        if (!$user instanceof UserInterface) {
            return false;
        }

        /** @var ApiToken $apiToken */
        $apiToken = $subject;

        return match ($attribute) {
            self::VIEW => $this->canView($apiToken, $user),
            self::EDIT => $this->canEdit($apiToken, $user),
            default => throw new \LogicException('This code should not be reached!')
        };
    }
    private function canView(ApiToken $apiToken, User $user): bool
    {
        // if they can edit, they can view
        if ($this->canEdit($apiToken, $user)) {
            return true;
        }

        // the Post object could have, for example, a method `isPrivate()`
        return false;
    }

    private function canEdit(ApiToken $apiToken, User $user): bool
    {
        // this assumes that the Post object has a `getOwner()` method
        return $user === $apiToken->getUser();
    }
}
