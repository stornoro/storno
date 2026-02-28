<?php

namespace App\Controller\Api\V1;

use App\Entity\OrganizationMembership;
use App\Entity\User;
use App\Enum\OrganizationRole;
use App\Enum\UserRoles;
use App\Repository\CompanyRepository;
use App\Repository\OrganizationMembershipRepository;
use App\Security\OrganizationContext;
use App\Security\Permission;
use App\Security\RolePermissionMap;
use App\Service\LicenseManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/api/v1/members')]
class MemberController extends AbstractController
{
    public function __construct(
        private readonly OrganizationMembershipRepository $membershipRepository,
        private readonly CompanyRepository $companyRepository,
        private readonly OrganizationContext $organizationContext,
        private readonly EntityManagerInterface $entityManager,
        private readonly LicenseManager $licenseManager,
    ) {}

    #[Route('', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $org = $this->organizationContext->getOrganization();
        if (!$org) {
            return $this->json(['error' => 'No organization found.'], Response::HTTP_NOT_FOUND);
        }

        $currentUser = $this->getUser();
        $canManage = $this->organizationContext->hasPermission(Permission::ORG_MANAGE_MEMBERS);

        $memberships = $this->membershipRepository->findBy(['organization' => $org]);

        $members = array_map(
            fn(OrganizationMembership $m) => $this->serializeMember($m, $currentUser),
            $memberships,
        );

        return $this->json([
            'data' => $members,
            'meta' => [
                'canManage' => $canManage,
                'maxUsers' => $this->licenseManager->getMaxUsers($org),
                'currentCount' => count(array_filter($memberships, fn($m) => $m->isActive())),
            ],
        ]);
    }

    #[Route('/permissions-reference', methods: ['GET'])]
    public function permissionsReference(): JsonResponse
    {
        $org = $this->organizationContext->getOrganization();
        if (!$org) {
            return $this->json(['error' => 'No organization found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::ORG_MANAGE_MEMBERS)) {
            return $this->json(['error' => 'Forbidden.'], Response::HTTP_FORBIDDEN);
        }

        // Group all permissions by category (prefix before the dot)
        $grouped = [];
        foreach (Permission::all() as $perm) {
            $category = explode('.', $perm, 2)[0];
            $grouped[$category][] = $perm;
        }

        // Role defaults
        $roleDefaults = [];
        foreach (OrganizationRole::cases() as $role) {
            $roleDefaults[$role->value] = RolePermissionMap::getPermissions($role);
        }

        return $this->json([
            'permissions' => $grouped,
            'roleDefaults' => $roleDefaults,
        ]);
    }

    #[Route('/{uuid}', methods: ['PATCH'])]
    public function update(string $uuid, Request $request): JsonResponse
    {
        $org = $this->organizationContext->getOrganization();
        if (!$org) {
            return $this->json(['error' => 'No organization found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::ORG_MANAGE_MEMBERS)) {
            return $this->json(['error' => 'Forbidden.'], Response::HTTP_FORBIDDEN);
        }

        $membership = $this->membershipRepository->find(Uuid::fromString($uuid));
        if (!$membership || $membership->getOrganization() !== $org) {
            return $this->json(['error' => 'Member not found.'], Response::HTTP_NOT_FOUND);
        }

        $currentUser = $this->getUser();
        $data = json_decode($request->getContent(), true);

        // Super admin members cannot be modified by non-super-admins
        if (in_array(UserRoles::ROLE_SUPER_ADMIN, $membership->getUser()->getRoles(), true) && !in_array(UserRoles::ROLE_SUPER_ADMIN, $currentUser->getRoles(), true)) {
            return $this->json(['error' => 'Nu puteti modifica un super administrator.'], Response::HTTP_FORBIDDEN);
        }

        // Cannot change own role
        if (isset($data['role']) && $membership->getUser() === $currentUser) {
            return $this->json(['error' => 'Nu va puteti schimba propriul rol.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Role change validation
        if (isset($data['role'])) {
            $newRole = OrganizationRole::tryFrom($data['role']);
            if (!$newRole) {
                return $this->json(['error' => 'Invalid role.'], Response::HTTP_BAD_REQUEST);
            }

            $currentMembership = $this->organizationContext->getMembership();

            // Cannot promote to OWNER unless you are OWNER
            if ($newRole === OrganizationRole::OWNER && $currentMembership->getRole() !== OrganizationRole::OWNER) {
                return $this->json(['error' => 'Doar un proprietar poate promova la rol de proprietar.'], Response::HTTP_FORBIDDEN);
            }

            // Cannot change OWNER role unless you are OWNER
            if ($membership->getRole() === OrganizationRole::OWNER && $currentMembership->getRole() !== OrganizationRole::OWNER) {
                return $this->json(['error' => 'Nu puteti modifica rolul unui proprietar.'], Response::HTTP_FORBIDDEN);
            }

            // At least one OWNER must remain
            if ($membership->getRole() === OrganizationRole::OWNER && $newRole !== OrganizationRole::OWNER) {
                $ownerCount = $this->membershipRepository->count([
                    'organization' => $org,
                    'role' => OrganizationRole::OWNER,
                    'isActive' => true,
                ]);
                if ($ownerCount <= 1) {
                    return $this->json(['error' => 'Trebuie sa existe cel putin un proprietar.'], Response::HTTP_UNPROCESSABLE_ENTITY);
                }
            }

            $membership->setRole($newRole);
        }

        if (array_key_exists('isActive', $data)) {
            // Cannot deactivate yourself
            if ($membership->getUser() === $currentUser) {
                return $this->json(['error' => 'Nu va puteti dezactiva propriul cont.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $membership->setIsActive((bool) $data['isActive']);
        }

        // Update allowed companies (only for ACCOUNTANT/EMPLOYEE)
        if (array_key_exists('allowedCompanies', $data)) {
            $role = $membership->getRole();
            if ($role !== OrganizationRole::OWNER && $role !== OrganizationRole::ADMIN) {
                $membership->clearAllowedCompanies();
                $companyIds = $data['allowedCompanies'] ?? [];
                foreach ($companyIds as $companyId) {
                    $company = $this->companyRepository->find(Uuid::fromString($companyId));
                    if ($company && $company->getOrganization() === $org) {
                        $membership->addAllowedCompany($company);
                    }
                }
            }
        }

        // Update custom permissions
        if (array_key_exists('permissions', $data)) {
            $perms = $data['permissions'];
            if ($perms === null || (is_array($perms) && empty($perms))) {
                // Reset to role defaults
                $membership->setPermissions([]);
            } elseif (is_array($perms)) {
                $allValid = Permission::all();
                $currentUserPerms = $this->getCurrentUserPermissions();
                $validated = [];
                foreach ($perms as $p) {
                    if (!in_array($p, $allValid, true)) {
                        return $this->json(['error' => "Permisiune invalida: $p"], Response::HTTP_BAD_REQUEST);
                    }
                    if (!in_array($p, $currentUserPerms, true)) {
                        return $this->json(['error' => "Nu puteti acorda permisiunea: $p"], Response::HTTP_FORBIDDEN);
                    }
                    $validated[] = $p;
                }
                $membership->setPermissions(array_values(array_unique($validated)));
            }
        }

        $this->entityManager->flush();

        return $this->json($this->serializeMember($membership, $currentUser));
    }

    #[Route('/{uuid}', methods: ['DELETE'])]
    public function delete(string $uuid): JsonResponse
    {
        $org = $this->organizationContext->getOrganization();
        if (!$org) {
            return $this->json(['error' => 'No organization found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::ORG_MANAGE_MEMBERS)) {
            return $this->json(['error' => 'Forbidden.'], Response::HTTP_FORBIDDEN);
        }

        $membership = $this->membershipRepository->find(Uuid::fromString($uuid));
        if (!$membership || $membership->getOrganization() !== $org) {
            return $this->json(['error' => 'Member not found.'], Response::HTTP_NOT_FOUND);
        }

        $currentUser = $this->getUser();

        // Super admin members cannot be deactivated by non-super-admins
        if (in_array(UserRoles::ROLE_SUPER_ADMIN, $membership->getUser()->getRoles(), true) && !in_array(UserRoles::ROLE_SUPER_ADMIN, $currentUser->getRoles(), true)) {
            return $this->json(['error' => 'Nu puteti dezactiva un super administrator.'], Response::HTTP_FORBIDDEN);
        }

        // Cannot deactivate yourself
        if ($membership->getUser() === $currentUser) {
            return $this->json(['error' => 'Nu va puteti dezactiva propriul cont.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Cannot deactivate OWNER unless you are OWNER
        if ($membership->getRole() === OrganizationRole::OWNER) {
            $currentMembership = $this->organizationContext->getMembership();
            if ($currentMembership->getRole() !== OrganizationRole::OWNER) {
                return $this->json(['error' => 'Nu puteti dezactiva un proprietar.'], Response::HTTP_FORBIDDEN);
            }
        }

        $membership->setIsActive(false);
        $this->entityManager->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    private function serializeMember(OrganizationMembership $membership, ?User $currentUser): array
    {
        $user = $membership->getUser();

        $allowedCompanies = [];
        foreach ($membership->getAllowedCompanies() as $company) {
            $allowedCompanies[] = [
                'id' => (string) $company->getId(),
                'name' => $company->getName(),
                'cif' => $company->getCif(),
            ];
        }

        return [
            'id' => (string) $membership->getId(),
            'user' => [
                'id' => (string) $user->getId(),
                'email' => $user->getEmail(),
                'firstName' => $user->getFirstName(),
                'lastName' => $user->getLastName(),
                'lastConnectedAt' => $user->getLastConnectedAt()?->format('c'),
            ],
            'role' => $membership->getRole()->value,
            'isActive' => $membership->isActive(),
            'joinedAt' => $membership->getJoinedAt()->format('c'),
            'allowedCompanies' => $allowedCompanies,
            'isCurrentUser' => $currentUser instanceof User && $user->getId()->equals($currentUser->getId()),
            'isSuperAdmin' => in_array(UserRoles::ROLE_SUPER_ADMIN, $user->getRoles(), true),
            'permissions' => $membership->getPermissions(),
            'hasCustomPermissions' => !empty($membership->getPermissions()),
        ];
    }

    private function getCurrentUserPermissions(): array
    {
        $membership = $this->organizationContext->getMembership();
        if (!$membership) {
            return [];
        }

        if (!empty($membership->getPermissions())) {
            return $membership->getPermissions();
        }

        return RolePermissionMap::getPermissions($membership->getRole());
    }
}
