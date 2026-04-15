<?php

namespace App\EventSubscriber\Auth;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Checks if the authenticated user is still active on every API request.
 * Returns 403 with code "account_inactive" if the account was deactivated
 * after the JWT was issued.
 */
class InactiveUserSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Security $security,
    ) {}

    public static function getSubscribedEvents(): array
    {
        // Run after the firewall (priority < 8)
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 6],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return;
        }

        if (!$user->isActive()) {
            $event->setResponse(new JsonResponse([
                'error' => 'Your account has been deactivated. Contact your administrator.',
                'code' => 'account_inactive',
            ], Response::HTTP_FORBIDDEN));
        }
    }
}
