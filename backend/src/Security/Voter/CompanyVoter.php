<?php

namespace App\Security\Voter;

use App\Entity\Company;
use App\Entity\User;
use App\Enum\OrganizationRole;
use App\Security\OrganizationContext;
use App\Security\Permission;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class CompanyVoter extends Voter
{
    public const VIEW = 'COMPANY_VIEW';
    public const EDIT = 'COMPANY_EDIT';
    public const DELETE = 'COMPANY_DELETE';

    public function __construct(
        private readonly OrganizationContext $organizationContext,
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT, self::DELETE])
            && $subject instanceof Company;
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

        /** @var Company $company */
        $company = $subject;

        // Company must belong to the current organization
        $org = $this->organizationContext->getOrganization();
        if ($org === null || $company->getOrganization() !== $org) {
            return false;
        }

        // Per-company access check for non-OWNER/ADMIN roles
        $membership = $this->organizationContext->getMembership();
        if ($membership) {
            $role = $membership->getRole();
            if ($role !== OrganizationRole::OWNER && $role !== OrganizationRole::ADMIN) {
                if (!$membership->hasAccessToCompany($company)) {
                    return false;
                }
            }
        }

        return match ($attribute) {
            self::VIEW => $this->organizationContext->hasPermission(Permission::COMPANY_VIEW),
            self::EDIT => $this->organizationContext->hasPermission(Permission::COMPANY_EDIT),
            self::DELETE => $this->organizationContext->hasPermission(Permission::COMPANY_DELETE),
            default => false,
        };
    }
}
