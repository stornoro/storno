<?php

namespace App\Controller\Api\V1;

use App\Entity\OAuth2AccessToken;
use App\Entity\OAuth2AuthorizationCode;
use App\Entity\OAuth2RefreshToken;
use App\Repository\OAuth2AccessTokenRepository;
use App\Repository\OAuth2AuthorizationCodeRepository;
use App\Repository\OAuth2ClientRepository;
use App\Repository\OAuth2RefreshTokenRepository;
use App\Security\OrganizationContext;
use App\Security\Permission;
use App\Service\OAuth2TokenService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/oauth2')]
class OAuth2AuthorizationController extends AbstractController
{
    public function __construct(
        private readonly OAuth2ClientRepository $clientRepository,
        private readonly OAuth2AuthorizationCodeRepository $authCodeRepository,
        private readonly OAuth2AccessTokenRepository $accessTokenRepository,
        private readonly OAuth2RefreshTokenRepository $refreshTokenRepository,
        private readonly OrganizationContext $organizationContext,
        private readonly EntityManagerInterface $entityManager,
        private readonly OAuth2TokenService $tokenService,
        private readonly LoggerInterface $apiLogger,
        private readonly RateLimiterFactory $oauth2TokenLimiter,
    ) {}

    /**
     * GET /api/v1/oauth2/authorize — Return client info for consent screen.
     * Requires authentication (JWT firewall).
     */
    #[Route('/authorize', methods: ['GET'])]
    public function authorizeInfo(Request $request): JsonResponse
    {
        $clientId = $request->query->get('client_id');
        $redirectUri = $request->query->get('redirect_uri');
        $scope = $request->query->get('scope', '');
        $responseType = $request->query->get('response_type');

        if ($responseType !== 'code') {
            return $this->json(['error' => 'unsupported_response_type', 'error_description' => 'Only response_type=code is supported.'], Response::HTTP_BAD_REQUEST);
        }

        if (!$clientId) {
            return $this->json(['error' => 'invalid_request', 'error_description' => 'client_id is required.'], Response::HTTP_BAD_REQUEST);
        }

        $client = $this->clientRepository->findByClientId($clientId);
        if (!$client || !$client->isUsable()) {
            return $this->json(['error' => 'invalid_client', 'error_description' => 'Client not found or inactive.'], Response::HTTP_BAD_REQUEST);
        }

        if (!$redirectUri || !$client->hasRedirectUri($redirectUri)) {
            return $this->json(['error' => 'invalid_redirect_uri', 'error_description' => 'Redirect URI not registered for this client.'], Response::HTTP_BAD_REQUEST);
        }

        $requestedScopes = $scope ? explode(' ', $scope) : [];
        $allowedScopes = $client->getScopes();

        // Validate requested scopes are within client's allowed scopes
        $invalidScopes = array_diff($requestedScopes, $allowedScopes);
        if (!empty($invalidScopes)) {
            return $this->json(['error' => 'invalid_scope', 'error_description' => 'Invalid scopes: ' . implode(', ', $invalidScopes)], Response::HTTP_BAD_REQUEST);
        }

        // If no scopes requested, use all client scopes
        $effectiveScopes = !empty($requestedScopes) ? $requestedScopes : $allowedScopes;

        return $this->json([
            'data' => [
                'client' => [
                    'name' => $client->getName(),
                    'description' => $client->getDescription(),
                    'logoUrl' => $client->getLogoUrl(),
                    'websiteUrl' => $client->getWebsiteUrl(),
                ],
                'requestedScopes' => $effectiveScopes,
            ],
        ]);
    }

    /**
     * POST /api/v1/oauth2/authorize — User approves or denies the authorization request.
     * Requires authentication (JWT firewall).
     */
    #[Route('/authorize', methods: ['POST'])]
    public function authorize(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        $clientId = $data['client_id'] ?? null;
        $redirectUri = $data['redirect_uri'] ?? null;
        $scope = $data['scope'] ?? '';
        $state = $data['state'] ?? null;
        $codeChallenge = $data['code_challenge'] ?? null;
        $codeChallengeMethod = $data['code_challenge_method'] ?? null;
        $approved = $data['approved'] ?? false;

        if (!$clientId) {
            return $this->json(['error' => 'invalid_request', 'error_description' => 'client_id is required.'], Response::HTTP_BAD_REQUEST);
        }

        $client = $this->clientRepository->findByClientId($clientId);
        if (!$client || !$client->isUsable()) {
            return $this->json(['error' => 'invalid_client', 'error_description' => 'Client not found or inactive.'], Response::HTTP_BAD_REQUEST);
        }

        if (!$redirectUri || !$client->hasRedirectUri($redirectUri)) {
            return $this->json(['error' => 'invalid_redirect_uri', 'error_description' => 'Redirect URI not registered.'], Response::HTTP_BAD_REQUEST);
        }

        // Build redirect with error if denied
        if (!$approved) {
            $params = ['error' => 'access_denied'];
            if ($state) {
                $params['state'] = $state;
            }

            return $this->json(['redirect_uri' => $redirectUri . '?' . http_build_query($params)]);
        }

        // PKCE validation
        if ($client->isPublic() && !$codeChallenge) {
            return $this->json(['error' => 'invalid_request', 'error_description' => 'code_challenge is required for public clients.'], Response::HTTP_BAD_REQUEST);
        }

        if ($codeChallenge && $codeChallengeMethod !== 'S256') {
            return $this->json(['error' => 'invalid_request', 'error_description' => 'Only S256 code_challenge_method is supported.'], Response::HTTP_BAD_REQUEST);
        }

        $requestedScopes = $scope ? explode(' ', $scope) : $client->getScopes();
        $allowedScopes = $client->getScopes();
        $effectiveScopes = array_intersect($requestedScopes, $allowedScopes);

        // Generate authorization code
        $codeData = $this->tokenService->generateAuthorizationCode();

        $authCode = new OAuth2AuthorizationCode();
        $authCode->setClient($client);
        $authCode->setUser($this->getUser());
        $authCode->setOrganization($this->organizationContext->getOrganization());
        $authCode->setCodeHash($codeData['hash']);
        $authCode->setScopes(array_values($effectiveScopes));
        $authCode->setRedirectUri($redirectUri);

        if ($codeChallenge) {
            $authCode->setCodeChallenge($codeChallenge);
            $authCode->setCodeChallengeMethod($codeChallengeMethod);
        }

        $this->entityManager->persist($authCode);
        $this->entityManager->flush();

        $params = ['code' => $codeData['raw']];
        if ($state) {
            $params['state'] = $state;
        }

        return $this->json(['redirect_uri' => $redirectUri . '?' . http_build_query($params)]);
    }

    /**
     * POST /api/v1/oauth2/token — Exchange authorization code or refresh token.
     * Public endpoint (no auth required).
     */
    #[Route('/token', methods: ['POST'])]
    public function token(Request $request): JsonResponse
    {
        $limiter = $this->oauth2TokenLimiter->create($request->getClientIp());
        $limiter->consume(1)->ensureAccepted();

        $data = json_decode($request->getContent(), true);
        if (!$data) {
            // Support form-encoded as per OAuth2 spec
            $data = $request->request->all();
        }

        $grantType = $data['grant_type'] ?? null;

        return match ($grantType) {
            'authorization_code' => $this->handleAuthorizationCodeGrant($data),
            'refresh_token' => $this->handleRefreshTokenGrant($data),
            default => $this->json(['error' => 'unsupported_grant_type'], Response::HTTP_BAD_REQUEST),
        };
    }

    /**
     * POST /api/v1/oauth2/revoke — Revoke a token (RFC 7009).
     * Always returns 200.
     */
    #[Route('/revoke', methods: ['POST'])]
    public function revoke(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!$data) {
            $data = $request->request->all();
        }

        $token = $data['token'] ?? null;
        $clientId = $data['client_id'] ?? null;

        if (!$token) {
            return $this->json(null, Response::HTTP_OK);
        }

        // Authenticate client if provided
        if ($clientId) {
            $client = $this->clientRepository->findByClientId($clientId);
            if ($client && $client->isConfidential()) {
                $clientSecret = $data['client_secret'] ?? null;
                if (!$clientSecret || $this->tokenService->hashToken($clientSecret) !== $client->getClientSecretHash()) {
                    return $this->json(null, Response::HTTP_OK); // RFC 7009: always 200
                }
            }
        }

        $hash = $this->tokenService->hashToken($token);

        // Try access token first
        $accessToken = $this->accessTokenRepository->findOneByHash($hash);
        if ($accessToken) {
            $accessToken->setRevokedAt(new \DateTimeImmutable());
            $this->entityManager->flush();

            return $this->json(null, Response::HTTP_OK);
        }

        // Try refresh token
        $refreshToken = $this->refreshTokenRepository->findOneByHash($hash);
        if ($refreshToken) {
            $refreshToken->setRevokedAt(new \DateTimeImmutable());
            $this->entityManager->flush();

            return $this->json(null, Response::HTTP_OK);
        }

        // RFC 7009: always return 200 even if token not found
        return $this->json(null, Response::HTTP_OK);
    }

    private function handleAuthorizationCodeGrant(array $data): JsonResponse
    {
        $code = $data['code'] ?? null;
        $clientId = $data['client_id'] ?? null;
        $redirectUri = $data['redirect_uri'] ?? null;
        $codeVerifier = $data['code_verifier'] ?? null;
        $clientSecret = $data['client_secret'] ?? null;

        if (!$code || !$clientId || !$redirectUri) {
            return $this->json(['error' => 'invalid_request', 'error_description' => 'code, client_id, and redirect_uri are required.'], Response::HTTP_BAD_REQUEST);
        }

        $client = $this->clientRepository->findByClientId($clientId);
        if (!$client || !$client->isUsable()) {
            return $this->json(['error' => 'invalid_client'], Response::HTTP_UNAUTHORIZED);
        }

        // Verify client secret for confidential clients
        if ($client->isConfidential()) {
            if (!$clientSecret || $this->tokenService->hashToken($clientSecret) !== $client->getClientSecretHash()) {
                return $this->json(['error' => 'invalid_client', 'error_description' => 'Client authentication failed.'], Response::HTTP_UNAUTHORIZED);
            }
        }

        // Look up authorization code
        $codeHash = $this->tokenService->hashToken($code);
        $authCode = $this->authCodeRepository->findOneByCodeHash($codeHash);

        if (!$authCode) {
            return $this->json(['error' => 'invalid_grant', 'error_description' => 'Authorization code not found.'], Response::HTTP_BAD_REQUEST);
        }

        if ($authCode->isUsed()) {
            $this->apiLogger->warning('[OAuth2] Authorization code replay detected', [
                'code_id' => $authCode->getId()->toRfc4122(),
            ]);

            return $this->json(['error' => 'invalid_grant', 'error_description' => 'Authorization code already used.'], Response::HTTP_BAD_REQUEST);
        }

        if ($authCode->isExpired()) {
            return $this->json(['error' => 'invalid_grant', 'error_description' => 'Authorization code expired.'], Response::HTTP_BAD_REQUEST);
        }

        if ($authCode->getClient() !== $client) {
            return $this->json(['error' => 'invalid_grant', 'error_description' => 'Client mismatch.'], Response::HTTP_BAD_REQUEST);
        }

        if ($authCode->getRedirectUri() !== $redirectUri) {
            return $this->json(['error' => 'invalid_grant', 'error_description' => 'Redirect URI mismatch.'], Response::HTTP_BAD_REQUEST);
        }

        // Verify PKCE
        if ($authCode->getCodeChallenge()) {
            if (!$codeVerifier) {
                return $this->json(['error' => 'invalid_grant', 'error_description' => 'code_verifier is required.'], Response::HTTP_BAD_REQUEST);
            }
            if (!$this->tokenService->verifyPkce($codeVerifier, $authCode->getCodeChallenge())) {
                return $this->json(['error' => 'invalid_grant', 'error_description' => 'PKCE verification failed.'], Response::HTTP_BAD_REQUEST);
            }
        }

        // Mark code as used
        $authCode->setUsedAt(new \DateTimeImmutable());

        // Generate tokens
        $accessTokenData = $this->tokenService->generateAccessToken();
        $refreshTokenData = $this->tokenService->generateRefreshToken();
        $family = $this->tokenService->generateFamily();

        $accessToken = new OAuth2AccessToken();
        $accessToken->setClient($client);
        $accessToken->setUser($authCode->getUser());
        $accessToken->setOrganization($authCode->getOrganization());
        $accessToken->setTokenHash($accessTokenData['hash']);
        $accessToken->setTokenPrefix($accessTokenData['prefix']);
        $accessToken->setScopes($authCode->getScopes());

        $refreshToken = new OAuth2RefreshToken();
        $refreshToken->setClient($client);
        $refreshToken->setUser($authCode->getUser());
        $refreshToken->setOrganization($authCode->getOrganization());
        $refreshToken->setAccessToken($accessToken);
        $refreshToken->setTokenHash($refreshTokenData['hash']);
        $refreshToken->setScopes($authCode->getScopes());
        $refreshToken->setFamily($family);

        $this->entityManager->persist($accessToken);
        $this->entityManager->persist($refreshToken);
        $this->entityManager->flush();

        $this->apiLogger->info('[OAuth2] Token issued via authorization_code', [
            'client_id' => $clientId,
            'user_id' => $authCode->getUser()->getId()->toRfc4122(),
        ]);

        return $this->json([
            'access_token' => $accessTokenData['raw'],
            'refresh_token' => $refreshTokenData['raw'],
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'scope' => implode(' ', $authCode->getScopes()),
        ]);
    }

    private function handleRefreshTokenGrant(array $data): JsonResponse
    {
        $rawRefreshToken = $data['refresh_token'] ?? null;
        $clientId = $data['client_id'] ?? null;
        $clientSecret = $data['client_secret'] ?? null;

        if (!$rawRefreshToken || !$clientId) {
            return $this->json(['error' => 'invalid_request', 'error_description' => 'refresh_token and client_id are required.'], Response::HTTP_BAD_REQUEST);
        }

        $client = $this->clientRepository->findByClientId($clientId);
        if (!$client || !$client->isUsable()) {
            return $this->json(['error' => 'invalid_client'], Response::HTTP_UNAUTHORIZED);
        }

        // Verify client secret for confidential clients
        if ($client->isConfidential()) {
            if (!$clientSecret || $this->tokenService->hashToken($clientSecret) !== $client->getClientSecretHash()) {
                return $this->json(['error' => 'invalid_client', 'error_description' => 'Client authentication failed.'], Response::HTTP_UNAUTHORIZED);
            }
        }

        $refreshHash = $this->tokenService->hashToken($rawRefreshToken);
        $refreshToken = $this->refreshTokenRepository->findOneByHash($refreshHash);

        if (!$refreshToken) {
            return $this->json(['error' => 'invalid_grant', 'error_description' => 'Refresh token not found.'], Response::HTTP_BAD_REQUEST);
        }

        // Replay detection: if already revoked, revoke entire family
        if ($refreshToken->isRevoked()) {
            $this->apiLogger->warning('[OAuth2] Refresh token replay detected, revoking family', [
                'family' => $refreshToken->getFamily(),
                'client_id' => $clientId,
            ]);
            $this->refreshTokenRepository->revokeFamily($refreshToken->getFamily());

            return $this->json(['error' => 'invalid_grant', 'error_description' => 'Refresh token has been revoked. All tokens in this chain have been invalidated.'], Response::HTTP_BAD_REQUEST);
        }

        if ($refreshToken->isExpired()) {
            return $this->json(['error' => 'invalid_grant', 'error_description' => 'Refresh token expired.'], Response::HTTP_BAD_REQUEST);
        }

        if ($refreshToken->getClient() !== $client) {
            return $this->json(['error' => 'invalid_grant', 'error_description' => 'Client mismatch.'], Response::HTTP_BAD_REQUEST);
        }

        // Revoke old refresh token (rotation)
        $refreshToken->setRevokedAt(new \DateTimeImmutable());

        // Revoke old access token if it exists
        $oldAccessToken = $refreshToken->getAccessToken();
        if ($oldAccessToken && !$oldAccessToken->isRevoked()) {
            $oldAccessToken->setRevokedAt(new \DateTimeImmutable());
        }

        // Generate new tokens with same family
        $accessTokenData = $this->tokenService->generateAccessToken();
        $refreshTokenData = $this->tokenService->generateRefreshToken();

        $newAccessToken = new OAuth2AccessToken();
        $newAccessToken->setClient($client);
        $newAccessToken->setUser($refreshToken->getUser());
        $newAccessToken->setOrganization($refreshToken->getOrganization());
        $newAccessToken->setTokenHash($accessTokenData['hash']);
        $newAccessToken->setTokenPrefix($accessTokenData['prefix']);
        $newAccessToken->setScopes($refreshToken->getScopes());

        $newRefreshToken = new OAuth2RefreshToken();
        $newRefreshToken->setClient($client);
        $newRefreshToken->setUser($refreshToken->getUser());
        $newRefreshToken->setOrganization($refreshToken->getOrganization());
        $newRefreshToken->setAccessToken($newAccessToken);
        $newRefreshToken->setTokenHash($refreshTokenData['hash']);
        $newRefreshToken->setScopes($refreshToken->getScopes());
        $newRefreshToken->setFamily($refreshToken->getFamily());

        $this->entityManager->persist($newAccessToken);
        $this->entityManager->persist($newRefreshToken);
        $this->entityManager->flush();

        $this->apiLogger->info('[OAuth2] Token refreshed', [
            'client_id' => $clientId,
            'user_id' => $refreshToken->getUser()->getId()->toRfc4122(),
        ]);

        return $this->json([
            'access_token' => $accessTokenData['raw'],
            'refresh_token' => $refreshTokenData['raw'],
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'scope' => implode(' ', $refreshToken->getScopes()),
        ]);
    }
}
