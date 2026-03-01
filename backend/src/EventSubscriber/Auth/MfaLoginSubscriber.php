<?php

namespace App\EventSubscriber\Auth;

use App\Entity\User;
use App\Service\MfaService;
use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationSuccessEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class MfaLoginSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly MfaService $mfaService,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            Events::AUTHENTICATION_SUCCESS => ['onAuthenticationSuccess', 10],
        ];
    }

    public function onAuthenticationSuccess(AuthenticationSuccessEvent $event): void
    {
        $user = $event->getUser();

        if (!$user instanceof User) {
            return;
        }

        if (!$user->requiresMfa()) {
            return;
        }

        // Create MFA challenge token
        $mfaToken = $this->mfaService->createMfaChallenge($user);

        $methods = $user->getAvailableMfaMethods();
        if ($this->mfaService->getMfaStatus($user)['backupCodesRemaining'] > 0) {
            $methods[] = 'backup_code';
        }

        // Replace the response data with MFA challenge
        $event->setData([
            'mfa_required' => true,
            'mfa_token' => $mfaToken,
            'mfa_methods' => $methods,
        ]);
    }
}
