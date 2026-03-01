<?php

namespace App\Security\Voter;

use App\Entity\Invoice;
use App\Entity\User;
use App\Enum\OrganizationRole;
use App\Security\OrganizationContext;
use App\Security\Permission;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class InvoiceVoter extends Voter
{
    public const VIEW = 'INVOICE_VIEW';
    public const EDIT = 'INVOICE_EDIT';
    public const DELETE = 'INVOICE_DELETE';
    public const ISSUE = 'INVOICE_ISSUE';
    public const SEND = 'INVOICE_SEND';
    public const CANCEL = 'INVOICE_CANCEL';
    public const REFUND = 'INVOICE_REFUND';

    public function __construct(
        private readonly OrganizationContext $organizationContext,
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [
            self::VIEW, self::EDIT, self::DELETE,
            self::ISSUE, self::SEND, self::CANCEL, self::REFUND,
        ]) && $subject instanceof Invoice;
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

        /** @var Invoice $invoice */
        $invoice = $subject;

        $org = $this->organizationContext->getOrganization();
        if ($org === null || $invoice->getCompany()?->getOrganization() !== $org) {
            return false;
        }

        // Per-company access check for non-OWNER/ADMIN roles
        $membership = $this->organizationContext->getMembership();
        if ($membership) {
            $role = $membership->getRole();
            if ($role !== OrganizationRole::OWNER && $role !== OrganizationRole::ADMIN) {
                if ($invoice->getCompany() && !$membership->hasAccessToCompany($invoice->getCompany())) {
                    return false;
                }
            }
        }

        return match ($attribute) {
            self::VIEW => $this->organizationContext->hasPermission(Permission::INVOICE_VIEW),
            self::EDIT => $this->organizationContext->hasPermission(Permission::INVOICE_EDIT),
            self::DELETE => $this->organizationContext->hasPermission(Permission::INVOICE_DELETE),
            self::ISSUE => $this->organizationContext->hasPermission(Permission::INVOICE_ISSUE),
            self::SEND => $this->organizationContext->hasPermission(Permission::INVOICE_SEND),
            self::CANCEL => $this->organizationContext->hasPermission(Permission::INVOICE_CANCEL),
            self::REFUND => $this->organizationContext->hasPermission(Permission::INVOICE_REFUND),
            default => false,
        };
    }
}
