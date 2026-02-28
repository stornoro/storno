<?php

declare(strict_types=1);

namespace App\Security;

use App\Repository\StripeAppTokenRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Authenticates requests from the Stripe App using the X-Stripe-App-Token header.
 */
class StripeAppTokenAuthenticator extends AbstractAuthenticator
{
    private const HEADER = 'X-Stripe-App-Token';

    public function __construct(
        private readonly StripeAppTokenRepository $tokenRepository,
    ) {}

    public function supports(Request $request): ?bool
    {
        return $request->headers->has(self::HEADER);
    }

    public function authenticate(Request $request): Passport
    {
        $token = $request->headers->get(self::HEADER);

        if (!$token) {
            throw new CustomUserMessageAuthenticationException('No Stripe App token provided');
        }

        $appToken = $this->tokenRepository->findValidByAccessToken($token);

        if (!$appToken) {
            throw new CustomUserMessageAuthenticationException('Invalid or expired Stripe App token');
        }

        $user = $appToken->getUser();

        if (!$user) {
            throw new CustomUserMessageAuthenticationException('Stripe App token has no associated user');
        }

        return new SelfValidatingPassport(
            new UserBadge($user->getUserIdentifier(), fn () => $user)
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new JsonResponse([
            'error' => 'authentication_failed',
            'message' => $exception->getMessageKey(),
        ], Response::HTTP_UNAUTHORIZED);
    }
}
