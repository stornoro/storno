<?php

namespace App\Controller\Api\Auth;

use App\Entity\Organization;
use App\Entity\OrganizationMembership;
use App\Entity\User;
use App\Enum\OrganizationRole;
use App\Message\SendEmailConfirmationMessage;
use App\Service\LicenseManager;
use App\Service\LicenseValidationService;
use App\Service\TurnstileVerifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class RegisterController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly ValidatorInterface $validator,
        private readonly SluggerInterface $slugger,
        private readonly MessageBusInterface $messageBus,
        private readonly LicenseValidationService $licenseValidationService,
        private readonly LicenseManager $licenseManager,
        private readonly TurnstileVerifier $turnstileVerifier,
        private readonly bool $registrationEnabled,
    ) {}

    #[Route('/api/auth/register', name: 'api_auth_register', methods: ['POST'])]
    public function register(Request $request, RateLimiterFactory $registerLimiter): JsonResponse
    {
        if (!$this->registrationEnabled) {
            return $this->json(['error' => 'Registration is temporarily disabled.'], Response::HTTP_FORBIDDEN);
        }

        $limiter = $registerLimiter->create($request->getClientIp());
        if (!$limiter->consume()->isAccepted()) {
            return $this->json(['error' => 'Too many registration attempts. Please try again later.'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $data = json_decode($request->getContent(), true);

        // Verify Turnstile
        $turnstileToken = $data['turnstileToken'] ?? '';
        if (!$this->turnstileVerifier->verify($turnstileToken, $request->getClientIp())) {
            return $this->json(['error' => 'Captcha verification failed.'], Response::HTTP_FORBIDDEN);
        }

        if (empty($data['email']) || empty($data['password'])) {
            return $this->json(['error' => 'Email and password are required.'], Response::HTTP_BAD_REQUEST);
        }

        $existing = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $data['email']]);
        if ($existing) {
            return $this->json(['error' => 'An account with this email already exists.'], Response::HTTP_CONFLICT);
        }

        $user = new User();
        $user->setEmail($data['email']);
        $user->setFirstName($data['firstName'] ?? null);
        $user->setLastName($data['lastName'] ?? null);
        $user->setPassword($this->passwordHasher->hashPassword($user, $data['password']));
        $user->setActive(true);
        $user->setEmailVerified(false);
        $user->setRoles(['ROLE_USER']);

        // Self-hosted / Community Edition: block creating additional organizations
        if ($this->licenseValidationService->isSelfHosted() || $this->licenseManager->isCommunityEdition()) {
            $existingOrgCount = (int) $this->entityManager->getConnection()
                ->fetchOne('SELECT COUNT(*) FROM organization');
            if ($existingOrgCount > 0) {
                return $this->json([
                    'error' => 'Self-hosted instances support a single organization. Ask the admin to invite you.',
                ], Response::HTTP_FORBIDDEN);
            }
        }

        // Create default organization
        $orgName = $data['organizationName'] ?? ($user->getFullName() . "'s Organization");
        $organization = new Organization();
        $organization->setName($orgName);
        $organization->setSlug($this->slugger->slug($orgName)->lower()->toString() . '-' . substr(md5(uniqid()), 0, 6));

        // Create owner membership
        $membership = new OrganizationMembership();
        $membership->setUser($user);
        $membership->setOrganization($organization);
        $membership->setRole(OrganizationRole::OWNER);
        $membership->setIsActive(true);

        $this->entityManager->persist($user);
        $this->entityManager->persist($organization);
        $this->entityManager->persist($membership);
        $this->entityManager->flush();

        // Start 14-day trial (grants Starter features)
        $this->licenseManager->startTrial($organization);
        $this->entityManager->flush();

        // Send email confirmation
        $this->messageBus->dispatch(new SendEmailConfirmationMessage((string) $user->getId()));

        return $this->json([
            'message' => 'Registration successful. Please check your email to confirm your account.',
            'email' => $user->getEmail(),
        ], Response::HTTP_CREATED);
    }
}
