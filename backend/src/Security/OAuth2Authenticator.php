<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Entity\UserFailedLoginAttempt;
use App\Repository\OAuth2AccessTokenRepository;
use App\Repository\UserFailedLoginAttemptRepository;
use App\Service\OAuth2TokenService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

class OAuth2Authenticator extends AbstractAuthenticator
{
    private const BEARER_PREFIX = 'Bearer storno_oat_';

    public function __construct(
        private readonly OAuth2AccessTokenRepository $accessTokenRepository,
        private readonly UserFailedLoginAttemptRepository $failedLoginAttemptRepository,
        private readonly LoggerInterface $apiLogger,
        private readonly OAuth2TokenService $tokenService,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function supports(Request $request): ?bool
    {
        $authHeader = $request->headers->get('Authorization');

        return $authHeader !== null && str_starts_with($authHeader, self::BEARER_PREFIX);
    }

    public function authenticate(Request $request): Passport
    {
        $authHeader = $request->headers->get('Authorization');
        $rawToken = substr($authHeader, 7); // Strip "Bearer "

        $this->apiLogger->info('[OAuth2] Starting authentication', [
            'token_prefix' => substr($rawToken, 0, 12) . '...',
            'ip' => $request->getClientIp(),
        ]);

        $hash = $this->tokenService->hashToken($rawToken);
        $accessToken = $this->accessTokenRepository->findOneByHash($hash);

        if (!$accessToken) {
            throw new BadCredentialsException('Invalid OAuth2 access token');
        }

        if ($accessToken->isRevoked()) {
            throw new CustomUserMessageAuthenticationException('OAuth2 access token has been revoked');
        }

        if ($accessToken->isExpired()) {
            throw new CustomUserMessageAuthenticationException('OAuth2 access token has expired');
        }

        // Update lastUsedAt
        $accessToken->setLastUsedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        // Store token on request for scope enforcement in OrganizationContext
        $request->attributes->set('_oauth2_access_token', $accessToken);

        // Set organization header from token if not already set
        if (!$request->headers->has('X-Organization') && $accessToken->getOrganization()) {
            $request->headers->set('X-Organization', $accessToken->getOrganization()->getId()->toRfc4122());
        }

        /** @var User $user */
        $user = $accessToken->getUser();

        $this->apiLogger->info('Authenticated successfully via OAuth2 token', [
            'user' => $user->getUserIdentifier(),
            'token_prefix' => $accessToken->getTokenPrefix(),
            'client_id' => $accessToken->getClient()?->getClientId(),
        ]);

        return new SelfValidatingPassport(new UserBadge($user->getUserIdentifier(), fn () => $user));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $this->failedLoginAttemptRepository->save(UserFailedLoginAttempt::createFromRequest($request));

        $this->apiLogger->info(sprintf("OAuth2 authentication failed: %s\n", $exception->getMessage()));

        return new JsonResponse([
            'status_code' => Response::HTTP_UNAUTHORIZED,
            'message' => 'Invalid OAuth2 token',
            'error_code' => 'OAUTH2_AUTH_FAILED',
        ], Response::HTTP_UNAUTHORIZED);
    }
}
