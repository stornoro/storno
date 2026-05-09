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
    private const CAPTCHA_REMAINING_THRESHOLD = 3;

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

        if ($request->getPathInfo() !== '/api/auth') {
            return;
        }

        $userAgent = $request->headers->get('User-Agent', '');
        $isMobile = str_contains($userAgent, 'Expo/') || str_contains($userAgent, 'okhttp/');

        $limiter = $this->loginLimiter->create($request->getClientIp());

        if (!$isMobile) {
            // Peek without consuming — once budget is low, gate behind captcha
            // before the rate limit triggers a hard lockout.
            $peek = $limiter->consume(0);
            if ($peek->getRemainingTokens() <= self::CAPTCHA_REMAINING_THRESHOLD) {
                $data = json_decode($request->getContent(), true);
                $token = $data['turnstileToken'] ?? '';
                if (!$this->turnstileVerifier->verify($token, $request->getClientIp())) {
                    throw new CustomUserMessageAuthenticationException('captcha_required');
                }
            }
        }

        if (!$limiter->consume()->isAccepted()) {
            throw new CustomUserMessageAuthenticationException('Too many requests.');
        }
    }
}
