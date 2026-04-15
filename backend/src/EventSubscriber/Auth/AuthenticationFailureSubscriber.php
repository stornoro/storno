<?php

namespace App\EventSubscriber\Auth;

use App\Security\Exception\AccountDeniedLoginException;
use App\Security\Exception\AccountMailNotConfirmedException;
use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationFailureEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Lexik\Bundle\JWTAuthenticationBundle\Response\JWTAuthenticationFailureResponse;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

class AuthenticationFailureSubscriber implements EventSubscriberInterface
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    public static function getSubscribedEvents()
    {
        return [
            Events::AUTHENTICATION_FAILURE => "onAuthenticationFailure"
        ];
    }

    public function onAuthenticationFailure(AuthenticationFailureEvent $event): void
    {
        $exception = $event->getException();
        $previous = $exception->getPrevious();

        // Account deactivated — return specific code so clients can show a clear message
        if ($previous instanceof AccountDeniedLoginException) {
            $event->setResponse(new JsonResponse([
                'error' => $this->translator->trans($previous->getMessageKey()),
                'code' => 'account_inactive',
            ], Response::HTTP_FORBIDDEN));
            return;
        }

        // Email not confirmed
        if ($previous instanceof AccountMailNotConfirmedException) {
            $event->setResponse(new JsonResponse([
                'error' => $this->translator->trans($previous->getMessageKey()),
                'code' => 'email_not_confirmed',
            ], Response::HTTP_FORBIDDEN));
            return;
        }

        /** @var JWTAuthenticationFailureResponse $response */
        $response = $event->getResponse();
        $response->setMessage($this->translator->trans($response->getMessage()));
    }
}
