<?php

namespace App\Controller\Api\Auth;

use App\Entity\User;
use App\Entity\UserPasskey;
use App\Repository\UserPasskeyRepository;
use App\Service\WebAuthnService;
use Doctrine\ORM\EntityManagerInterface;
use Gesdinet\JWTRefreshTokenBundle\Generator\RefreshTokenGeneratorInterface;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;

class PasskeyController extends AbstractController
{
    private const CHALLENGE_TTL = 300; // 5 minutes

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly JWTTokenManagerInterface $jwtManager,
        private readonly RefreshTokenGeneratorInterface $refreshTokenGenerator,
        private readonly RefreshTokenManagerInterface $refreshTokenManager,
        private readonly CacheInterface $cache,
        private readonly UserPasskeyRepository $passkeyRepository,
        private readonly WebAuthnService $webAuthnService,
    ) {}

    // ── Registration: Generate creation options (JWT required) ──────────────

    #[Route('/api/v1/passkey/register/options', name: 'api_passkey_register_options', methods: ['POST'])]
    public function registerOptions(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $data = json_decode($request->getContent(), true) ?? [];
        $rpId = $this->webAuthnService->getRpId($request);

        $userEntity = PublicKeyCredentialUserEntity::create(
            $user->getEmail(),
            $user->getId()->toBinary(),
            $user->getFullName(),
        );

        $rpEntity = PublicKeyCredentialRpEntity::create('Storno.ro', $rpId);

        // Exclude already registered credentials
        $excludeCredentials = [];
        foreach ($user->getPasskeys() as $passkey) {
            $excludeCredentials[] = PublicKeyCredentialDescriptor::create(
                PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
                base64_decode($passkey->getCredentialId()),
            );
        }

        $challenge = random_bytes(32);

        $creationOptions = new PublicKeyCredentialCreationOptions(
            rp: $rpEntity,
            user: $userEntity,
            challenge: $challenge,
            pubKeyCredParams: [
                PublicKeyCredentialParameters::create('public-key', -7),  // ES256
                PublicKeyCredentialParameters::create('public-key', -257), // RS256
            ],
            authenticatorSelection: AuthenticatorSelectionCriteria::create(
                residentKey: AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_PREFERRED,
                userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_PREFERRED,
            ),
            attestation: PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            excludeCredentials: $excludeCredentials,
            timeout: 60000,
        );

        // Store challenge in cache
        $challengeKey = 'passkey_register_' . $user->getId()->toRfc4122();
        $serializer = $this->webAuthnService->getSerializer();
        $this->cache->delete($challengeKey);
        $this->cache->get($challengeKey, function (ItemInterface $item) use ($creationOptions, $serializer) {
            $item->expiresAfter(self::CHALLENGE_TTL);
            return $serializer->serialize($creationOptions, 'json');
        });

        return new JsonResponse(
            $serializer->serialize($creationOptions, 'json'),
            Response::HTTP_OK,
            [],
            true
        );
    }

    // ── Registration: Verify attestation (JWT required) ─────────────────────

    #[Route('/api/v1/passkey/register', name: 'api_passkey_register', methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $data = json_decode($request->getContent(), true);
        $rpId = $this->webAuthnService->getRpId($request);

        // Retrieve stored creation options
        $challengeKey = 'passkey_register_' . $user->getId()->toRfc4122();
        $storedJson = $this->cache->get($challengeKey, function () {
            return null;
        });

        if (!$storedJson) {
            return $this->json(['error' => 'Challenge expired or not found.'], Response::HTTP_BAD_REQUEST);
        }

        $serializer = $this->webAuthnService->getSerializer();

        /** @var PublicKeyCredentialCreationOptions $creationOptions */
        $creationOptions = $serializer->deserialize($storedJson, PublicKeyCredentialCreationOptions::class, 'json');

        // Parse the credential from the request
        $credential = $data['credential'] ?? $data;

        /** @var PublicKeyCredential $publicKeyCredential */
        $publicKeyCredential = $serializer->denormalize($credential, PublicKeyCredential::class, 'json');

        $response = $publicKeyCredential->response;
        if (!$response instanceof AuthenticatorAttestationResponse) {
            return $this->json(['error' => 'Invalid response type.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $factory = new CeremonyStepManagerFactory();
            $factory->setAllowedOrigins($this->webAuthnService->getAllowedOrigins($request));
            $validator = AuthenticatorAttestationResponseValidator::create($factory->creationCeremony());

            $publicKeyCredentialSource = $validator->check($response, $creationOptions, $rpId);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'Attestation verification failed: ' . $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        // Store the passkey
        $passkey = new UserPasskey();
        $passkey->setUser($user);
        $passkey->setCredentialId(base64_encode($publicKeyCredentialSource->publicKeyCredentialId));
        $passkey->setPublicKeyCredentialSource($serializer->serialize($publicKeyCredentialSource, 'json'));
        $passkey->setName($data['name'] ?? null);

        $this->em->persist($passkey);
        $this->em->flush();

        // Clean up challenge
        $this->cache->delete($challengeKey);

        return $this->json([
            'id' => $passkey->getId()->toRfc4122(),
            'name' => $passkey->getName(),
            'createdAt' => $passkey->getCreatedAt()->format('c'),
        ], Response::HTTP_CREATED);
    }

    // ── Login: Generate request options (Public) ────────────────────────────

    #[Route('/api/auth/passkey/login/options', name: 'api_passkey_login_options', methods: ['POST'])]
    public function loginOptions(Request $request, RateLimiterFactory $oauthLoginLimiter): JsonResponse
    {
        $limiter = $oauthLoginLimiter->create($request->getClientIp());
        if (!$limiter->consume()->isAccepted()) {
            return $this->json(['error' => 'Too many requests.'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $rpId = $this->webAuthnService->getRpId($request);
        $challenge = random_bytes(32);

        $requestOptions = PublicKeyCredentialRequestOptions::create(
            challenge: $challenge,
            rpId: $rpId,
            userVerification: PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_PREFERRED,
            timeout: 60000,
        );

        // Store challenge with a unique key
        $serializer = $this->webAuthnService->getSerializer();
        $sessionId = bin2hex(random_bytes(16));
        $challengeKey = 'passkey_login_' . $sessionId;
        $this->cache->delete($challengeKey);
        $this->cache->get($challengeKey, function (ItemInterface $item) use ($requestOptions, $serializer) {
            $item->expiresAfter(self::CHALLENGE_TTL);
            return $serializer->serialize($requestOptions, 'json');
        });

        $responseData = json_decode($serializer->serialize($requestOptions, 'json'), true);
        $responseData['sessionId'] = $sessionId;

        return $this->json($responseData);
    }

    // ── Login: Verify assertion (Public) ────────────────────────────────────

    #[Route('/api/auth/passkey/login', name: 'api_passkey_login', methods: ['POST'])]
    public function login(Request $request, RateLimiterFactory $oauthLoginLimiter): JsonResponse
    {
        $limiter = $oauthLoginLimiter->create($request->getClientIp());
        if (!$limiter->consume()->isAccepted()) {
            return $this->json(['error' => 'Too many requests.'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $data = json_decode($request->getContent(), true);
        $sessionId = $data['sessionId'] ?? null;

        if (!$sessionId) {
            return $this->json(['error' => 'Missing session ID.'], Response::HTTP_BAD_REQUEST);
        }

        // Retrieve stored request options
        $challengeKey = 'passkey_login_' . $sessionId;
        $storedJson = $this->cache->get($challengeKey, function () {
            return null;
        });

        if (!$storedJson) {
            return $this->json(['error' => 'Challenge expired or not found.'], Response::HTTP_BAD_REQUEST);
        }

        $credential = $data['credential'] ?? $data;
        $passkey = $this->webAuthnService->verifyAssertion($storedJson, $credential, $request);

        if (!$passkey) {
            return $this->json(['error' => 'Assertion verification failed.'], Response::HTTP_UNAUTHORIZED);
        }

        $user = $passkey->getUser();
        $user->setLastConnectedAt(new \DateTimeImmutable());

        $this->em->flush();

        // Clean up challenge
        $this->cache->delete($challengeKey);

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

    // ── Management: List passkeys (JWT required) ────────────────────────────

    #[Route('/api/v1/me/passkeys', name: 'api_me_passkeys_list', methods: ['GET'])]
    public function listPasskeys(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $passkeys = [];
        foreach ($user->getPasskeys() as $passkey) {
            $passkeys[] = [
                'id' => $passkey->getId()->toRfc4122(),
                'name' => $passkey->getName(),
                'createdAt' => $passkey->getCreatedAt()->format('c'),
                'lastUsedAt' => $passkey->getLastUsedAt()?->format('c'),
            ];
        }

        return $this->json($passkeys);
    }

    // ── Management: Delete passkey (JWT required) ───────────────────────────

    #[Route('/api/v1/me/passkeys/{id}', name: 'api_me_passkeys_delete', methods: ['DELETE'])]
    public function deletePasskey(string $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $passkey = $this->passkeyRepository->find($id);

        if (!$passkey || $passkey->getUser()->getId()->toRfc4122() !== $user->getId()->toRfc4122()) {
            return $this->json(['error' => 'Passkey not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->em->remove($passkey);
        $this->em->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

}
