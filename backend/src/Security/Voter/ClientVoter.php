<?php

namespace App\Security\Voter;

use App\Entity\Client;
use App\Entity\User;
use App\Enum\OrganizationRole;
use App\Security\OrganizationContext;
use App\Security\Permission;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class ClientVoter extends Voter
{
    public const VIEW = 'CLIENT_VIEW';
    public const EDIT = 'CLIENT_EDIT';
    public const DELETE = 'CLIENT_DELETE';

    public function __construct(
        private readonly OrganizationContext $organizationContext,
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT, self::DELETE])
            && $subject instanceof Client;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        if (in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
            return true;
        }

        /** @var Client $client */
        $client = $subject;

        $org = $this->organizationContext->getOrganization();
        if ($org === null || $client->getCompany()?->getOrganization() !== $org) {
            return false;
        }

        // Per-company access check for non-OWNER/ADMIN roles
        $membership = $this->organizationContext->getMembership();
        if ($membership) {
            $role = $membership->getRole();
            if ($role !== OrganizationRole::OWNER && $role !== OrganizationRole::ADMIN) {
                if ($client->getCompany() && !$membership->hasAccessToCompany($client->getCompany())) {
                    return false;
                }
            }
        }

        return match ($attribute) {
            self::VIEW => $this->organizationContext->hasPermission(Permission::CLIENT_VIEW),
            self::EDIT => $this->organizationContext->hasPermission(Permission::CLIENT_EDIT),
            self::DELETE => $this->organizationContext->hasPermission(Permission::CLIENT_DELETE),
            default => false,
        };
    }
}
