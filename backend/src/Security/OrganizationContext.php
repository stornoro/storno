<?php

namespace App\Security;

use App\Entity\Company;
use App\Entity\Organization;
use App\Entity\OrganizationMembership;
use App\Entity\User;
use App\Enum\OrganizationRole;
use App\Repository\CompanyRepository;
use App\Repository\OrganizationMembershipRepository;
use App\Repository\OrganizationRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

class OrganizationContext
{
    private ?Organization $currentOrganization = null;
    private ?OrganizationMembership $currentMembership = null;
    private bool $resolved = false;

    public function __construct(
        private readonly Security $security,
        private readonly RequestStack $requestStack,
        private readonly OrganizationRepository $organizationRepository,
        private readonly OrganizationMembershipRepository $membershipRepository,
        private readonly CompanyRepository $companyRepository,
    ) {}

    public function getOrganization(): ?Organization
    {
        $this->resolve();

        return $this->currentOrganization;
    }

    public function getMembership(): ?OrganizationMembership
    {
        $this->resolve();

        return $this->currentMembership;
    }

    public function hasPermission(string $permission): bool
    {
        $membership = $this->getMembership();

        if ($membership === null) {
            return false;
        }

        // Check custom permissions first
        if (!empty($membership->getPermissions())) {
            $basePermission = $membership->hasPermission($permission);
        } else {
            // Fall back to role defaults
            $basePermission = in_array($permission, RolePermissionMap::getPermissions($membership->getRole()), true);
        }

        // If authenticated via API key, intersect with token scopes
        $request = $this->requestStack->getCurrentRequest();
        $apiToken = $request?->attributes->get('_api_token');
        if ($apiToken instanceof \App\Entity\ApiToken) {
            return $basePermission && $apiToken->hasScope($permission);
        }

        // If authenticated via OAuth2 token, intersect with token scopes
        $oauth2Token = $request?->attributes->get('_oauth2_access_token');
        if ($oauth2Token instanceof \App\Entity\OAuth2AccessToken) {
            return $basePermission && $oauth2Token->hasScope($permission);
        }

        return $basePermission;
    }

    /**
     * Returns true if the current request was authenticated via API key or OAuth2 token
     * (as opposed to a JWT session). Used to block programmatic access to credential
     * management endpoints — only interactive sessions can create/manage tokens and apps.
     */
    public function isTokenAuth(): bool
    {
        $request = $this->requestStack->getCurrentRequest();

        return $request?->attributes->get('_api_token') instanceof \App\Entity\ApiToken
            || $request?->attributes->get('_oauth2_access_token') instanceof \App\Entity\OAuth2AccessToken;
    }

    public function resolveCompany(?Request $request = null): ?Company
    {
        $request = $request ?? $this->requestStack->getCurrentRequest();

        $companyId = $request?->query->get('company') ?? $request?->headers->get('X-Company');
        if ($companyId) {
            $company = $this->companyRepository->find(Uuid::fromString($companyId));

            // Validate per-company access for non-OWNER/ADMIN
            if ($company && $this->currentMembership) {
                $role = $this->currentMembership->getRole();
                if ($role !== OrganizationRole::OWNER && $role !== OrganizationRole::ADMIN) {
                    if (!$this->currentMembership->hasAccessToCompany($company)) {
                        return null;
                    }
                }
            }

            return $company;
        }

        $org = $this->getOrganization();
        if ($org) {
            $count = $this->companyRepository->count(['organization' => $org]);
            if ($count === 1) {
                return $this->companyRepository->findOneBy(['organization' => $org]);
            }
        }

        return null;
    }

    private function resolve(): void
    {
        if ($this->resolved) {
            return;
        }

        $this->resolved = true;

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return;
        }

        $isSuperAdmin = in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true);

        $request = $this->requestStack->getCurrentRequest();
        $orgId = $request?->headers->get('X-Organization');

        if ($orgId) {
            $org = $this->organizationRepository->find($orgId);
            if ($org) {
                // Super admins can use any organization without membership check
                if ($isSuperAdmin) {
                    $this->currentOrganization = $org;
                    $this->currentMembership = $this->membershipRepository->findByUserAndOrganization($user, $org);

                    return;
                }

                $membership = $this->membershipRepository->findByUserAndOrganization($user, $org);
                if ($membership && $membership->isActive()) {
                    $this->currentOrganization = $org;
                    $this->currentMembership = $membership;

                    return;
                }
            }
        }

        // Fallback: auto-select best organization
        $memberships = $this->membershipRepository->findActiveByUser($user);
        if (count($memberships) === 1) {
            $membership = $memberships[0];
            $this->currentOrganization = $membership->getOrganization();
            $this->currentMembership = $membership;
        } elseif (count($memberships) > 1) {
            // Multiple orgs: prefer one that has companies, then OWNER role
            $best = null;
            foreach ($memberships as $m) {
                $companyCount = $this->companyRepository->count(['organization' => $m->getOrganization()]);
                if ($companyCount > 0) {
                    $best = $m;
                    break;
                }
            }
            // Fallback to the OWNER membership (user's own org)
            if (!$best) {
                foreach ($memberships as $m) {
                    if ($m->getRole() === OrganizationRole::OWNER) {
                        $best = $m;
                        break;
                    }
                }
            }
            $best = $best ?? $memberships[0];
            $this->currentOrganization = $best->getOrganization();
            $this->currentMembership = $best;
        }
    }
}
