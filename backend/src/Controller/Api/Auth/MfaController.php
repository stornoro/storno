<?php

namespace App\Controller\Api\Auth;

use App\Entity\User;
use App\Service\MfaService;
use App\Service\WebAuthnService;
use Doctrine\ORM\EntityManagerInterface;
use Gesdinet\JWTRefreshTokenBundle\Generator\RefreshTokenGeneratorInterface;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class MfaController extends AbstractController
{
    private const CHALLENGE_TTL = 300; // 5 minutes

    public function __construct(
        private readonly MfaService $mfaService,
        private readonly EntityManagerInterface $em,
        private readonly JWTTokenManagerInterface $jwtManager,
        private readonly RefreshTokenGeneratorInterface $refreshTokenGenerator,
        private readonly RefreshTokenManagerInterface $refreshTokenManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly WebAuthnService $webAuthnService,
        private readonly CacheInterface $cache,
    ) {}

    // ── Public: Get passkey assertion options for MFA (no JWT required) ──

    #[Route('/api/auth/mfa/passkey/options', name: 'api_auth_mfa_passkey_options', methods: ['POST'])]
    public function passkeyOptions(Request $request, RateLimiterFactory $mfaVerifyLimiter): JsonResponse
    {
        $limiter = $mfaVerifyLimiter->create($request->getClientIp());
        if (!$limiter->consume()->isAccepted()) {
            return $this->json(['error' => 'Too many requests.'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $data = json_decode($request->getContent(), true);
        $mfaToken = $data['mfaToken'] ?? null;

        if (!$mfaToken) {
            return $this->json(['error' => 'Missing mfaToken.'], Response::HTTP_BAD_REQUEST);
        }

        $challengeData = $this->mfaService->validateMfaChallenge($mfaToken);
        if (!$challengeData) {
            return $this->json(['error' => 'Invalid or expired MFA challenge.'], Response::HTTP_UNAUTHORIZED);
        }

        $user = $this->em->getRepository(User::class)->find($challengeData['userId']);
        if (!$user || $user->getPasskeys()->isEmpty()) {
            return $this->json(['error' => 'No passkeys available.'], Response::HTTP_BAD_REQUEST);
        }

        $result = $this->webAuthnService->createAssertionOptions($user, $request);

        // Store options in cache keyed by mfaToken
        $cacheKey = 'mfa_passkey_' . $mfaToken;
        $this->cache->delete($cacheKey);
        $this->cache->get($cacheKey, function (ItemInterface $item) use ($result) {
            $item->expiresAfter(self::CHALLENGE_TTL);
            return $result['optionsJson'];
        });

        return new JsonResponse($result['optionsJson'], Response::HTTP_OK, [], true);
    }

    // ── Public: Verify MFA challenge (no JWT required) ───────────────────

    #[Route('/api/auth/mfa/verify', name: 'api_auth_mfa_verify', methods: ['POST'])]
    public function verify(Request $request, RateLimiterFactory $mfaVerifyLimiter): JsonResponse
    {
        $limiter = $mfaVerifyLimiter->create($request->getClientIp());
        if (!$limiter->consume()->isAccepted()) {
            return $this->json(['error' => 'Too many requests.'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $data = json_decode($request->getContent(), true);
        $mfaToken = $data['mfaToken'] ?? null;
        $code = $data['code'] ?? null;
        $type = $data['type'] ?? 'totp'; // 'totp', 'backup', or 'passkey'
        $credential = $data['credential'] ?? null;

        if (!$mfaToken) {
            return $this->json(['error' => 'Missing mfaToken.'], Response::HTTP_BAD_REQUEST);
        }

        if ($type !== 'passkey' && !$code) {
            return $this->json(['error' => 'Missing code.'], Response::HTTP_BAD_REQUEST);
        }

        $challengeData = $this->mfaService->validateMfaChallenge($mfaToken);
        if (!$challengeData) {
            return $this->json(['error' => 'Invalid or expired MFA challenge.'], Response::HTTP_UNAUTHORIZED);
        }

        $user = $this->em->getRepository(User::class)->find($challengeData['userId']);
        if (!$user) {
            return $this->json(['error' => 'User not found.'], Response::HTTP_UNAUTHORIZED);
        }

        $verified = false;

        if ($type === 'totp') {
            $verified = $this->mfaService->verifyTotpCode($user, $code);
        } elseif ($type === 'backup') {
            $verified = $this->mfaService->verifyBackupCode($user, $code);
        } elseif ($type === 'passkey') {
            if (!$credential) {
                return $this->json(['error' => 'Missing credential.'], Response::HTTP_BAD_REQUEST);
            }
            $cacheKey = 'mfa_passkey_' . $mfaToken;
            $storedOptionsJson = $this->cache->get($cacheKey, function () {
                return null;
            });
            if (!$storedOptionsJson) {
                return $this->json(['error' => 'Passkey challenge expired.'], Response::HTTP_BAD_REQUEST);
            }
            $passkey = $this->webAuthnService->verifyAssertion($storedOptionsJson, $credential, $request);
            if ($passkey && $passkey->getUser()->getId()->toRfc4122() === $user->getId()->toRfc4122()) {
                $verified = true;
            }
            $this->cache->delete($cacheKey);
        }

        if (!$verified) {
            $this->mfaService->incrementMfaAttempts($mfaToken);
            return $this->json(['error' => 'Invalid code.'], Response::HTTP_UNAUTHORIZED);
        }

        // Delete challenge token (single-use)
        $this->mfaService->deleteMfaChallenge($mfaToken);

        // Generate JWT + refresh token
        $jwt = $this->jwtManager->create($user);
        $refreshToken = $this->refreshTokenGenerator->createForUserWithTtl(
            $user,
            (new \DateTime())->modify('+30 days')->getTimestamp()
        );
        $this->refreshTokenManager->save($refreshToken);

        $user->setLastConnectedAt(new \DateTimeImmutable());
        $this->em->flush();

        return $this->json([
            'token' => $jwt,
            'refresh_token' => $refreshToken->getRefreshToken(),
        ]);
    }

    // ── Authenticated: MFA status ────────────────────────────────────────

    #[Route('/api/v1/me/mfa/status', name: 'api_me_mfa_status', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function status(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->json($this->mfaService->getMfaStatus($user));
    }

    // ── Authenticated: Setup TOTP ────────────────────────────────────────

    #[Route('/api/v1/me/mfa/totp/setup', name: 'api_me_mfa_totp_setup', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function setupTotp(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        if ($user->isMfaEnabled()) {
            return $this->json(['error' => 'TOTP is already enabled.'], Response::HTTP_CONFLICT);
        }

        $result = $this->mfaService->generateTotpSecret($user);

        return $this->json($result);
    }

    // ── Authenticated: Enable TOTP (verify first code) ───────────────────

    #[Route('/api/v1/me/mfa/totp/enable', name: 'api_me_mfa_totp_enable', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function enableTotp(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $data = json_decode($request->getContent(), true);
        $code = $data['code'] ?? null;

        if (!$code) {
            return $this->json(['error' => 'Missing code.'], Response::HTTP_BAD_REQUEST);
        }

        if ($user->isMfaEnabled()) {
            return $this->json(['error' => 'TOTP is already enabled.'], Response::HTTP_CONFLICT);
        }

        $backupCodes = $this->mfaService->enableTotp($user, $code);

        if ($backupCodes === null) {
            return $this->json(['error' => 'Invalid code. Please try again.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json([
            'enabled' => true,
            'backupCodes' => $backupCodes,
        ]);
    }

    // ── Authenticated: Disable TOTP (requires password) ──────────────────

    #[Route('/api/v1/me/mfa/totp/disable', name: 'api_me_mfa_totp_disable', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function disableTotp(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $data = json_decode($request->getContent(), true);
        $password = $data['password'] ?? null;

        if (!$password || !$this->passwordHasher->isPasswordValid($user, $password)) {
            return $this->json(['error' => 'Parola este incorecta.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->mfaService->disableTotp($user);

        return $this->json(['disabled' => true]);
    }

    // ── Authenticated: Regenerate backup codes (requires password) ───────

    #[Route('/api/v1/me/mfa/backup-codes/regenerate', name: 'api_me_mfa_backup_codes_regenerate', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function regenerateBackupCodes(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $data = json_decode($request->getContent(), true);
        $password = $data['password'] ?? null;

        if (!$password || !$this->passwordHasher->isPasswordValid($user, $password)) {
            return $this->json(['error' => 'Parola este incorecta.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (!$user->isMfaEnabled()) {
            return $this->json(['error' => 'MFA is not enabled.'], Response::HTTP_BAD_REQUEST);
        }

        $backupCodes = $this->mfaService->generateBackupCodes($user);

        return $this->json(['backupCodes' => $backupCodes]);
    }
}
