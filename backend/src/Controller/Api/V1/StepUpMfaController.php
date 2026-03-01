<?php

namespace App\Controller\Api\V1;

use App\Entity\User;
use App\Service\MfaService;
use App\Service\WebAuthnService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

#[Route('/api/v1/mfa')]
class StepUpMfaController extends AbstractController
{
    private const CHALLENGE_TTL = 300; // 5 minutes

    public function __construct(
        private readonly MfaService $mfaService,
        private readonly WebAuthnService $webAuthnService,
        private readonly EntityManagerInterface $em,
        private readonly CacheInterface $cache,
    ) {}

    #[Route('/challenge', methods: ['POST'])]
    public function challenge(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$user->requiresMfa()) {
            return $this->json(['mfa_required' => false]);
        }

        $challengeToken = $this->mfaService->createStepUpChallenge($user);
        $methods = $user->getAvailableMfaMethods();

        if ($this->mfaService->getMfaStatus($user)['backupCodesRemaining'] > 0) {
            $methods[] = 'backup_code';
        }

        return $this->json([
            'mfa_required' => true,
            'challenge_token' => $challengeToken,
            'methods' => $methods,
        ]);
    }

    #[Route('/passkey/options', methods: ['POST'])]
    public function passkeyOptions(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $data = json_decode($request->getContent(), true);
        $challengeToken = $data['challengeToken'] ?? null;

        if (!$challengeToken) {
            return $this->json(['error' => 'Missing challengeToken.'], Response::HTTP_BAD_REQUEST);
        }

        $challengeData = $this->mfaService->validateStepUpChallenge($challengeToken);
        if (!$challengeData || $challengeData['userId'] !== $user->getId()->toRfc4122()) {
            return $this->json(['error' => 'Invalid or expired challenge.'], Response::HTTP_UNAUTHORIZED);
        }

        if ($user->getPasskeys()->isEmpty()) {
            return $this->json(['error' => 'No passkeys available.'], Response::HTTP_BAD_REQUEST);
        }

        $result = $this->webAuthnService->createAssertionOptions($user, $request);

        $cacheKey = 'stepup_passkey_' . $challengeToken;
        $this->cache->delete($cacheKey);
        $this->cache->get($cacheKey, function (ItemInterface $item) use ($result) {
            $item->expiresAfter(self::CHALLENGE_TTL);
            return $result['optionsJson'];
        });

        return new JsonResponse($result['optionsJson'], Response::HTTP_OK, [], true);
    }

    #[Route('/verify', methods: ['POST'])]
    public function verify(Request $request, RateLimiterFactory $mfaVerifyLimiter): JsonResponse
    {
        $limiter = $mfaVerifyLimiter->create($request->getClientIp());
        if (!$limiter->consume()->isAccepted()) {
            return $this->json(['error' => 'Too many requests.'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        /** @var User $user */
        $user = $this->getUser();

        $data = json_decode($request->getContent(), true);
        $challengeToken = $data['challengeToken'] ?? null;
        $type = $data['type'] ?? 'totp';
        $code = $data['code'] ?? null;
        $credential = $data['credential'] ?? null;

        if (!$challengeToken) {
            return $this->json(['error' => 'Missing challengeToken.'], Response::HTTP_BAD_REQUEST);
        }

        $challengeData = $this->mfaService->validateStepUpChallenge($challengeToken);
        if (!$challengeData || $challengeData['userId'] !== $user->getId()->toRfc4122()) {
            return $this->json(['error' => 'Invalid or expired challenge.'], Response::HTTP_UNAUTHORIZED);
        }

        $verified = false;

        if ($type === 'totp') {
            if (!$code) {
                return $this->json(['error' => 'Missing code.'], Response::HTTP_BAD_REQUEST);
            }
            $verified = $this->mfaService->verifyTotpCode($user, $code);
        } elseif ($type === 'backup') {
            if (!$code) {
                return $this->json(['error' => 'Missing code.'], Response::HTTP_BAD_REQUEST);
            }
            $verified = $this->mfaService->verifyBackupCode($user, $code);
        } elseif ($type === 'passkey') {
            if (!$credential) {
                return $this->json(['error' => 'Missing credential.'], Response::HTTP_BAD_REQUEST);
            }
            $cacheKey = 'stepup_passkey_' . $challengeToken;
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
            $this->mfaService->incrementStepUpAttempts($challengeToken);
            return $this->json(['error' => 'Invalid verification.'], Response::HTTP_UNAUTHORIZED);
        }

        // Clean up challenge and issue verification token
        $this->mfaService->deleteStepUpChallenge($challengeToken);
        $verificationToken = $this->mfaService->createVerificationToken($user);

        return $this->json(['verification_token' => $verificationToken]);
    }
}
