<?php

namespace App\Security\Voter;

use App\Entity\User;
use App\Entity\WebhookEndpoint;
use App\Enum\OrganizationRole;
use App\Security\OrganizationContext;
use App\Security\Permission;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class WebhookVoter extends Voter
{
    public const VIEW = 'WEBHOOK_VIEW';
    public const MANAGE = 'WEBHOOK_MANAGE';

    public function __construct(
        private readonly OrganizationContext $organizationContext,
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::MANAGE])
            && $subject instanceof WebhookEndpoint;
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

        /** @var WebhookEndpoint $endpoint */
        $endpoint = $subject;

        // Endpoint's company must belong to the current organization
        $org = $this->organizationContext->getOrganization();
        if ($org === null || $endpoint->getCompany()->getOrganization() !== $org) {
            return false;
        }

        // Per-company access check for non-OWNER/ADMIN roles
        $membership = $this->organizationContext->getMembership();
        if ($membership) {
            $role = $membership->getRole();
            if ($role !== OrganizationRole::OWNER && $role !== OrganizationRole::ADMIN) {
                if (!$membership->hasAccessToCompany($endpoint->getCompany())) {
                    return false;
                }
            }
        }

        return match ($attribute) {
            self::VIEW => $this->organizationContext->hasPermission(Permission::WEBHOOK_VIEW),
            self::MANAGE => $this->organizationContext->hasPermission(Permission::WEBHOOK_MANAGE),
            default => false,
        };
    }
}
