<?php

namespace App\EventSubscriber\Auth;

use App\Service\TurnstileVerifier;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;

class TurnstileLoginSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly TurnstileVerifier $turnstileVerifier,
        private readonly RequestStack $requestStack,
        private readonly RateLimiterFactory $loginLimiter,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            CheckPassportEvent::class => ['onCheckPassport', 512],
        ];
    }

    public function onCheckPassport(CheckPassportEvent $event): void
    {
        $request = $this->requestStack->getCurrentRequest();
        if (!$request) {
            return;
        }

        // Only intercept the json_login endpoint
        if ($request->getPathInfo() !== '/api/auth') {
            return;
        }

        // Rate limit by IP
        $limiter = $this->loginLimiter->create($request->getClientIp());
        if (!$limiter->consume()->isAccepted()) {
            throw new CustomUserMessageAuthenticationException('Too many requests.');
        }

        // Skip Turnstile for mobile app requests (WebView widget cannot verify on native origins)
        $userAgent = $request->headers->get('User-Agent', '');
        if (str_contains($userAgent, 'Expo/') || str_contains($userAgent, 'okhttp/')) {
            return;
        }

        $data = json_decode($request->getContent(), true);
        $turnstileToken = $data['turnstileToken'] ?? '';

        if (!$this->turnstileVerifier->verify($turnstileToken, $request->getClientIp())) {
            throw new CustomUserMessageAuthenticationException('Captcha verification failed.');
        }
    }
}
