<?php

namespace App\Controller\Frontend;

use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[Route(path: '/api')]
class GoogleController extends AbstractController
{
    #[Route(path: '/connect/google', name: 'connect_google_start')]
    public function connectAction(ClientRegistry $clientRegistry, string $env): RedirectResponse
    {

        $redirectUri = $this->generateUrl(
            'connect_google_check',
            [],
            UrlGeneratorInterface::ABSOLUTE_URL
        );
        $redirectUri = $this->getParameter('app.google_oauth2_client_redirect_uri');
        $redirectUrl = $clientRegistry->getClient('google')
            ->redirect(
                ['profile', 'email'],
                [
                    'redirect_uri' => $redirectUri
                ]
            );
        // var_dump(($redirectUrl->getTargetUrl()));        exit;
        return $redirectUrl;
    }

    #[Route(path: '/connect/google/check', name: 'connect_google_check')]
    public function connectCheckAction(Request $request, ClientRegistry $clientRegistry)
    {
        return $this->json(['status' => 'fail'], 403);
    }
}
