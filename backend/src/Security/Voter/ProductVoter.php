<?php

namespace App\Security\Voter;

use App\Entity\Product;
use App\Entity\User;
use App\Enum\OrganizationRole;
use App\Security\OrganizationContext;
use App\Security\Permission;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class ProductVoter extends Voter
{
    public const VIEW = 'PRODUCT_VIEW';
    public const EDIT = 'PRODUCT_EDIT';
    public const DELETE = 'PRODUCT_DELETE';

    public function __construct(
        private readonly OrganizationContext $organizationContext,
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT, self::DELETE])
            && $subject instanceof Product;
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

        /** @var Product $product */
        $product = $subject;

        $org = $this->organizationContext->getOrganization();
        if ($org === null || $product->getCompany()?->getOrganization() !== $org) {
            return false;
        }

        // Per-company access check for non-OWNER/ADMIN roles
        $membership = $this->organizationContext->getMembership();
        if ($membership) {
            $role = $membership->getRole();
            if ($role !== OrganizationRole::OWNER && $role !== OrganizationRole::ADMIN) {
                if ($product->getCompany() && !$membership->hasAccessToCompany($product->getCompany())) {
                    return false;
                }
            }
        }

        return match ($attribute) {
            self::VIEW => $this->organizationContext->hasPermission(Permission::PRODUCT_VIEW),
            self::EDIT => $this->organizationContext->hasPermission(Permission::PRODUCT_EDIT),
            self::DELETE => $this->organizationContext->hasPermission(Permission::PRODUCT_DELETE),
            default => false,
        };
    }
}
