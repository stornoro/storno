<?php

namespace App\Controller\Api\V1;

use App\Entity\OrganizationInvitation;
use App\Entity\OrganizationMembership;
use App\Entity\User;
use App\Enum\OrganizationRole;
use App\Message\SendInvitationEmailMessage;
use App\Repository\CompanyRepository;
use App\Repository\OrganizationInvitationRepository;
use App\Repository\OrganizationMembershipRepository;
use App\Repository\UserRepository;
use App\Security\OrganizationContext;
use App\Security\Permission;
use App\Service\LicenseManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/api/v1/invitations')]
class InvitationController extends AbstractController
{
    public function __construct(
        private readonly OrganizationInvitationRepository $invitationRepository,
        private readonly OrganizationMembershipRepository $membershipRepository,
        private readonly UserRepository $userRepository,
        private readonly CompanyRepository $companyRepository,
        private readonly OrganizationContext $organizationContext,
        private readonly EntityManagerInterface $entityManager,
        private readonly LicenseManager $licenseManager,
        private readonly MessageBusInterface $messageBus,
    ) {}

    #[Route('', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $org = $this->organizationContext->getOrganization();
        if (!$org) {
            return $this->json(['error' => 'No organization found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::ORG_MANAGE_MEMBERS)) {
            return $this->json(['error' => 'Forbidden.'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        $email = mb_strtolower(trim($data['email'] ?? ''));
        $roleValue = $data['role'] ?? null;

        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json(['error' => 'Adresa de email invalida.'], Response::HTTP_BAD_REQUEST);
        }

        if (!$roleValue) {
            return $this->json(['error' => 'Rolul este obligatoriu.'], Response::HTTP_BAD_REQUEST);
        }

        $role = OrganizationRole::tryFrom($roleValue);
        if (!$role) {
            return $this->json(['error' => 'Rol invalid.'], Response::HTTP_BAD_REQUEST);
        }

        // Cannot invite as OWNER
        if ($role === OrganizationRole::OWNER) {
            return $this->json(['error' => 'Nu puteti invita cu rol de proprietar.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Check plan limit
        if (!$this->licenseManager->canAddMember($org)) {
            return $this->json([
                'error' => 'Limita de utilizatori atinsa. Upgradati planul.',
                'code' => 'PLAN_LIMIT',
            ], Response::HTTP_PAYMENT_REQUIRED);
        }

        // Check not already a member
        $existingUser = $this->userRepository->findOneBy(['email' => $email]);
        if ($existingUser) {
            $existingMembership = $this->membershipRepository->findOneBy([
                'user' => $existingUser,
                'organization' => $org,
            ]);
            if ($existingMembership) {
                return $this->json([
                    'error' => 'Acest utilizator este deja membru al organizatiei.',
                    'code' => 'ALREADY_MEMBER',
                ], Response::HTTP_CONFLICT);
            }
        }

        // Check no pending invitation
        $pendingInvitation = $this->invitationRepository->findPendingByEmailAndOrganization($email, $org);
        if ($pendingInvitation) {
            return $this->json([
                'error' => 'Exista deja o invitatie in asteptare pentru acest email.',
                'code' => 'ALREADY_INVITED',
            ], Response::HTTP_CONFLICT);
        }

        // Validate allowed companies (for accountant/employee roles)
        $allowedCompanyIds = [];
        if (isset($data['allowedCompanies']) && is_array($data['allowedCompanies'])) {
            foreach ($data['allowedCompanies'] as $companyId) {
                $company = $this->companyRepository->find(Uuid::fromString($companyId));
                if ($company && $company->getOrganization() === $org) {
                    $allowedCompanyIds[] = $companyId;
                }
            }
        }

        $invitation = (new OrganizationInvitation())
            ->setOrganization($org)
            ->setInvitedBy($this->getUser())
            ->setEmail($email)
            ->setRole($role)
            ->setAllowedCompanyIds($allowedCompanyIds);

        $this->entityManager->persist($invitation);
        $this->entityManager->flush();

        // Send email async
        $this->messageBus->dispatch(new SendInvitationEmailMessage((string) $invitation->getId()));

        return $this->json($this->serializeInvitation($invitation), Response::HTTP_CREATED);
    }

    #[Route('', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $org = $this->organizationContext->getOrganization();
        if (!$org) {
            return $this->json(['error' => 'No organization found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::ORG_MANAGE_MEMBERS)) {
            return $this->json(['error' => 'Forbidden.'], Response::HTTP_FORBIDDEN);
        }

        $invitations = $this->invitationRepository->findPendingByOrganization($org);

        return $this->json([
            'data' => array_map(fn($i) => $this->serializeInvitation($i), $invitations),
        ]);
    }

    #[Route('/{uuid}', methods: ['DELETE'])]
    public function cancel(string $uuid): JsonResponse
    {
        $org = $this->organizationContext->getOrganization();
        if (!$org) {
            return $this->json(['error' => 'No organization found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::ORG_MANAGE_MEMBERS)) {
            return $this->json(['error' => 'Forbidden.'], Response::HTTP_FORBIDDEN);
        }

        $invitation = $this->invitationRepository->find(Uuid::fromString($uuid));
        if (!$invitation || $invitation->getOrganization() !== $org) {
            return $this->json(['error' => 'Invitation not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$invitation->isPending()) {
            return $this->json(['error' => 'Invitatia nu mai poate fi anulata.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $invitation->cancel();
        $this->entityManager->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/{uuid}/resend', methods: ['POST'])]
    public function resend(string $uuid): JsonResponse
    {
        $org = $this->organizationContext->getOrganization();
        if (!$org) {
            return $this->json(['error' => 'No organization found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::ORG_MANAGE_MEMBERS)) {
            return $this->json(['error' => 'Forbidden.'], Response::HTTP_FORBIDDEN);
        }

        $invitation = $this->invitationRepository->find(Uuid::fromString($uuid));
        if (!$invitation || $invitation->getOrganization() !== $org) {
            return $this->json(['error' => 'Invitation not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$invitation->isPending()) {
            return $this->json(['error' => 'Invitatia nu mai este valida.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $invitation->resetExpiry();
        $this->entityManager->flush();

        $this->messageBus->dispatch(new SendInvitationEmailMessage((string) $invitation->getId()));

        return $this->json($this->serializeInvitation($invitation));
    }

    #[Route('/accept/{token}', methods: ['GET'])]
    public function acceptDetails(string $token): JsonResponse
    {
        $invitation = $this->invitationRepository->findValidByToken($token);
        if (!$invitation) {
            return $this->json(['error' => 'Invitatia este invalida sau a expirat.'], Response::HTTP_NOT_FOUND);
        }

        $existingUser = $this->userRepository->findOneBy(['email' => $invitation->getEmail()]);

        return $this->json([
            'email' => $invitation->getEmail(),
            'organizationName' => $invitation->getOrganization()->getName(),
            'role' => $invitation->getRole()->value,
            'expiresAt' => $invitation->getExpiresAt()->format('c'),
            'hasAccount' => $existingUser !== null,
        ]);
    }

    #[Route('/accept/{token}', methods: ['POST'])]
    public function accept(string $token): JsonResponse
    {
        $invitation = $this->invitationRepository->findValidByToken($token);
        if (!$invitation) {
            return $this->json(['error' => 'Invitatia este invalida sau a expirat.'], Response::HTTP_NOT_FOUND);
        }

        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Authentication required.'], Response::HTTP_UNAUTHORIZED);
        }

        // Verify email matches
        if (mb_strtolower($user->getEmail()) !== $invitation->getEmail()) {
            return $this->json([
                'error' => 'Adresa de email nu corespunde invitatiei.',
            ], Response::HTTP_FORBIDDEN);
        }

        $org = $invitation->getOrganization();

        // Check not already a member
        $existingMembership = $this->membershipRepository->findOneBy([
            'user' => $user,
            'organization' => $org,
        ]);
        if ($existingMembership) {
            $invitation->accept();
            $this->entityManager->flush();

            return $this->json([
                'error' => 'Sunteti deja membru al acestei organizatii.',
                'code' => 'ALREADY_MEMBER',
            ], Response::HTTP_CONFLICT);
        }

        // Re-check plan limit
        if (!$this->licenseManager->canAddMember($org)) {
            return $this->json([
                'error' => 'Organizatia a atins limita de utilizatori.',
                'code' => 'PLAN_LIMIT',
            ], Response::HTTP_PAYMENT_REQUIRED);
        }

        // Create membership
        $membership = (new OrganizationMembership())
            ->setUser($user)
            ->setOrganization($org)
            ->setRole($invitation->getRole())
            ->setPermissions([])
            ->setIsActive(true);

        // Apply company restrictions from invitation (for accountant/employee)
        $role = $invitation->getRole();
        if ($role !== OrganizationRole::OWNER && $role !== OrganizationRole::ADMIN) {
            foreach ($invitation->getAllowedCompanyIds() as $companyId) {
                $company = $this->companyRepository->find(Uuid::fromString($companyId));
                if ($company && $company->getOrganization() === $org) {
                    $membership->addAllowedCompany($company);
                }
            }
        }

        $this->entityManager->persist($membership);

        $invitation->accept();
        $this->entityManager->flush();

        return $this->json([
            'message' => 'Invitatie acceptata cu succes.',
            'organizationId' => (string) $org->getId(),
            'organizationName' => $org->getName(),
        ]);
    }

    private function serializeInvitation(OrganizationInvitation $invitation): array
    {
        $invitedBy = $invitation->getInvitedBy();

        // Resolve company names for display
        $allowedCompanies = [];
        foreach ($invitation->getAllowedCompanyIds() as $companyId) {
            $company = $this->companyRepository->find(Uuid::fromString($companyId));
            if ($company) {
                $allowedCompanies[] = [
                    'id' => (string) $company->getId(),
                    'name' => $company->getName(),
                    'cif' => $company->getCif(),
                ];
            }
        }

        return [
            'id' => (string) $invitation->getId(),
            'email' => $invitation->getEmail(),
            'role' => $invitation->getRole()->value,
            'status' => $invitation->getStatus()->value,
            'createdAt' => $invitation->getCreatedAt()->format('c'),
            'expiresAt' => $invitation->getExpiresAt()->format('c'),
            'invitedBy' => [
                'id' => (string) $invitedBy->getId(),
                'firstName' => $invitedBy->getFirstName(),
                'lastName' => $invitedBy->getLastName(),
            ],
            'allowedCompanies' => $allowedCompanies,
        ];
    }
}
