<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Entity\UserFailedLoginAttempt;
use App\Repository\ApiTokenRepository;
use App\Repository\UserFailedLoginAttemptRepository;
use App\Service\ApiTokenService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Psr\Log\LoggerInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Authenticate based on Api Token
 */
class ApiKeyAuthenticator extends AbstractAuthenticator
{
    const API_KEY = 'Authorization';

    public function __construct(
        private readonly ApiTokenRepository $apiTokenRepository,
        private readonly UserFailedLoginAttemptRepository $failedLoginAttemptRepository,
        private readonly LoggerInterface $apiLogger,
        private readonly RateLimiterFactory $authenticatedApiLimiter,
        private readonly ApiTokenService $apiTokenService,
        private readonly EntityManagerInterface $entityManager,
        private readonly string $frontendUrl,
    ) {}

    public function supports(Request $request): ?bool
    {
        $authHeader = $request->headers->get(self::API_KEY);
        if (null === $authHeader) {
            return false;
        }

        // Skip Bearer tokens (handled by JWT authenticator)
        if (str_starts_with($authHeader, 'Bearer ')) {
            return false;
        }

        return true;
    }

    public function authenticate(Request $request): Passport
    {
        $rawToken = $request->headers->get(self::API_KEY);

        $this->apiLogger->info('[API TOKEN] Starting authentication', [
            'token_prefix' => substr($rawToken, 0, 12) . '...',
            'ip' => $request->getClientIp(),
        ]);

        if (null === $rawToken) {
            throw new CustomUserMessageAuthenticationException('No API token provided');
        }

        // One token per request. Keyed by the token hash so the raw key never reaches the limiter store.
        $limit = $this->authenticatedApiLimiter->create(hash('sha256', $rawToken))->consume(1);
        if (!$limit->isAccepted()) {
            $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());

            throw new TooManyRequestsHttpException($retryAfter, 'Rate limit exceeded. Retry after ' . $retryAfter . ' seconds.');
        }

        // Hash the incoming token and look up by hash
        $hash = $this->apiTokenService->hashToken($rawToken);
        $apiToken = $this->apiTokenRepository->findOneByHash($hash);

        if (!$apiToken) {
            throw new BadCredentialsException('Invalid API token');
        }

        if (!$apiToken->isValid()) {
            if ($apiToken->isRevoked()) {
                throw new CustomUserMessageAuthenticationException('API token has been revoked');
            }
            if ($apiToken->isExpired()) {
                throw new CustomUserMessageAuthenticationException('API token has expired');
            }
        }

        // lastUsedAt is informative, minute precision is enough. Writing it on every request
        // turns a busy integration key into one row update per call.
        $now = new \DateTimeImmutable();
        $lastUsedAt = $apiToken->getLastUsedAt();
        if ($lastUsedAt === null || $now->getTimestamp() - $lastUsedAt->getTimestamp() >= 60) {
            $apiToken->setLastUsedAt($now);
            $this->entityManager->flush();
        }

        // Store ApiToken on request for scope enforcement
        $request->attributes->set('_api_token', $apiToken);

        /** @var User $user */
        $user = $apiToken->getUser();

        $this->apiLogger->info('Authenticated successfully via API token', [
            'user' => $user->getUserIdentifier(),
            'token_prefix' => $apiToken->getTokenPrefix(),
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

        $this->apiLogger->info(sprintf("API Token authentication failed: %s\n", $exception->getMessage()));
        $data = [
            'status_code' => Response::HTTP_FORBIDDEN,
            'detail' => $exception->getPrevious() ? $exception->getPrevious()->getMessage() : null,
            'message' => 'Invalid API Token',
            'error_code' => 'AUTH_HEADER_TOKEN',
        ];

        return new JsonResponse($data, Response::HTTP_FORBIDDEN);
    }
}
