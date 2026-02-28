<?php

namespace App\EventSubscriber\Auth;

use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationFailureEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Lexik\Bundle\JWTAuthenticationBundle\Response\JWTAuthenticationFailureResponse;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
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
        /** @var JWTAuthenticationFailureResponse $response */
        $response = $event->getResponse();
        $response->setMessage($this->translator->trans($response->getMessage()));
    }
}
