<?php

namespace App\Controller\Api\Auth;

use App\Entity\Organization;
use App\Entity\OrganizationMembership;
use App\Entity\User;
use App\Entity\UserBilling;
use App\Enum\OrganizationRole;
use App\Service\LicenseValidationService;
use App\Service\MfaService;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Gesdinet\JWTRefreshTokenBundle\Generator\RefreshTokenGeneratorInterface;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\SluggerInterface;

class GoogleAuthController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ParameterBagInterface $params,
        private readonly JWTTokenManagerInterface $jwtManager,
        private readonly RefreshTokenGeneratorInterface $refreshTokenGenerator,
        private readonly RefreshTokenManagerInterface $refreshTokenManager,
        private readonly SluggerInterface $slugger,
        private readonly LicenseValidationService $licenseValidationService,
        private readonly MfaService $mfaService,
    ) {}

    #[Route('/api/auth/google', name: 'api_auth_google', methods: ['POST'])]
    public function __invoke(Request $request, RateLimiterFactory $oauthLoginLimiter): JsonResponse
    {
        $limiter = $oauthLoginLimiter->create($request->getClientIp());
        if (!$limiter->consume()->isAccepted()) {
            return $this->json(['error' => 'Too many requests.'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $data = json_decode($request->getContent(), true);
        $credential = $data['credential'] ?? null;

        if (!$credential) {
            return $this->json(['error' => 'Missing credential.'], Response::HTTP_BAD_REQUEST);
        }

        // Verify the Google ID token
        $client = new \Google\Client([
            'client_id' => $this->params->get('app.google_oauth2_client_id'),
        ]);

        try {
            $payload = $client->verifyIdToken($credential);
        } catch (\Exception) {
            return $this->json(['error' => 'Invalid Google token.'], Response::HTTP_UNAUTHORIZED);
        }

        if (!$payload || empty($payload['email'])) {
            return $this->json(['error' => 'Invalid Google token.'], Response::HTTP_UNAUTHORIZED);
        }

        $email = $payload['email'];
        $googleId = $payload['sub'];
        $firstName = $payload['given_name'] ?? null;
        $lastName = $payload['family_name'] ?? null;
        $emailVerified = $payload['email_verified'] ?? false;

        // 1. Try find by googleId
        $user = $this->em->getRepository(User::class)->findOneBy(['googleId' => $googleId]);

        if (!$user) {
            // 2. Try find by email
            $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);

            if ($user) {
                // Link Google account to existing user
                $user->setGoogleId($googleId);
                if ($emailVerified) {
                    $user->setEmailVerified(true);
                }
            } else {
                // Self-hosted: block creating additional organizations
                if ($this->licenseValidationService->isSelfHosted()) {
                    $existingOrgCount = (int) $this->em->getConnection()
                        ->fetchOne('SELECT COUNT(*) FROM organization');
                    if ($existingOrgCount > 0) {
                        return $this->json([
                            'error' => 'Self-hosted instances support a single organization. Ask the admin to invite you.',
                        ], Response::HTTP_FORBIDDEN);
                    }
                }

                // 3. Create new user
                $user = new User();
                $user->setEmail($email);
                $user->setFirstName($firstName);
                $user->setLastName($lastName);
                $user->setEmailVerified($emailVerified);
                $user->setGoogleId($googleId);
                $user->setActive(true);
                $user->setRoles(['ROLE_USER']);
                $user->setUserBilling(
                    (new UserBilling())
                        ->setFirstName($firstName)
                        ->setLastName($lastName)
                );

                // Create default organization
                $orgName = trim(($firstName ?? '') . ' ' . ($lastName ?? '')) ?: $email;
                $organization = new Organization();
                $organization->setName($orgName . "'s Organization");
                $organization->setSlug(
                    $this->slugger->slug($orgName)->lower()->toString() . '-' . substr(md5(uniqid()), 0, 6)
                );

                $membership = new OrganizationMembership();
                $membership->setUser($user);
                $membership->setOrganization($organization);
                $membership->setRole(OrganizationRole::OWNER);
                $membership->setIsActive(true);

                $this->em->persist($organization);
                $this->em->persist($membership);
                $this->em->persist($user);
            }
        }

        $user->setLastConnectedAt(new \DateTimeImmutable());
        $this->em->flush();

        // Check if MFA is required — return challenge instead of JWT
        if ($user->requiresMfa()) {
            $mfaToken = $this->mfaService->createMfaChallenge($user);
            $methods = $user->getAvailableMfaMethods();
            $status = $this->mfaService->getMfaStatus($user);
            if ($status['backupCodesRemaining'] > 0) {
                $methods[] = 'backup_code';
            }

            return $this->json([
                'mfa_required' => true,
                'mfa_token' => $mfaToken,
                'mfa_methods' => $methods,
            ]);
        }

        // Generate JWT + refresh token
        $jwt = $this->jwtManager->create($user);

        $refreshToken = $this->refreshTokenGenerator->createForUserWithTtl(
            $user,
            (new \DateTime())->modify('+30 days')->getTimestamp()
        );
        $this->refreshTokenManager->save($refreshToken);

        return $this->json([
            'token' => $jwt,
            'refresh_token' => $refreshToken->getRefreshToken(),
        ]);
    }
}
