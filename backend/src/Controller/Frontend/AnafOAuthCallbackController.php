<?php

namespace App\Controller\Frontend;

use App\Entity\AnafToken;
use App\Events\Anaf\TokenCreatedEvent;
use App\Repository\AnafTokenLinkRepository;
use App\Service\Anaf\EFacturaClient;
use App\Service\Centrifugo\CentrifugoService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

/**
 * Handles the ANAF OAuth2 redirect callback.
 *
 * ANAF redirects here after the user authenticates with their certificate.
 * - Link-based flow  → exchange code, save token, render HTML result page
 * - Standard flow    → redirect to the frontend callback page with the code
 */
class AnafOAuthCallbackController extends AbstractController
{
    public function __construct(
        private readonly AnafTokenLinkRepository $linkRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly CentrifugoService $centrifugo,
        private readonly EFacturaClient $eFacturaClient,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {}

    #[Route(path: '/auth/callback/anaf', name: 'anaf_oauth_callback', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $code = $request->query->get('code');
        $stateParam = $request->query->get('state', '');

        // Parse state to detect flow type
        $state = $stateParam ? json_decode($stateParam, true) : null;
        $linkToken = $state['link'] ?? null;

        if (!$code) {
            if ($linkToken) {
                return $this->renderResultPage(false, 'Autorizarea a esuat. Va rugam incercati din nou.');
            }

            $frontendUrl = $this->getParameter('redirect_after_oauth2');

            return $this->redirect($frontendUrl . '/anaf?error=no_code');
        }

        if ($linkToken) {
            return $this->handleLinkCallback($code, $linkToken);
        }

        // Standard authenticated flow — redirect to frontend callback
        $frontendUrl = $this->getParameter('redirect_after_oauth2');
        $params = ['code' => $code];
        if ($stateParam) {
            $params['state'] = $stateParam;
        }

        return $this->redirect($frontendUrl . '/anaf?' . http_build_query($params));
    }

    private function handleLinkCallback(string $code, string $linkToken): Response
    {
        $link = $this->linkRepository->findValidByToken($linkToken);
        if (!$link) {
            return $this->renderResultPage(false, 'Link-ul a expirat sau a fost deja utilizat.');
        }

        $clientId = $this->getParameter('app.anaf_oauth2_client_id');
        $clientSecret = $this->getParameter('app.anaf_oauth2_client_secret');
        $callbackUrl = $this->getParameter('app.anaf_oauth2_redirect_uri');

        $content = $this->exchangeCodeForToken($code, $clientId, $clientSecret, $callbackUrl);
        if ($content === null) {
            return $this->renderResultPage(false, 'Nu s-a obtinut token-ul de la ANAF. Va rugam incercati din nou.');
        }

        $user = $link->getUser();

        $anafToken = new AnafToken();
        $anafToken
            ->setToken($content['access_token'])
            ->setRefreshToken($content['refresh_token'] ?? null)
            ->setExpireAt(new \DateTimeImmutable(sprintf('+%s seconds', $content['expires_in'])));

        // Validate against the link's company CIF, or all user companies
        $linkCompany = $link->getCompany();
        if ($linkCompany && $linkCompany->getCif()) {
            try {
                $validation = $this->eFacturaClient->validateToken((string) $linkCompany->getCif(), $anafToken->getToken());
                if ($validation['valid'] ?? false) {
                    $anafToken->addValidatedCif($linkCompany->getCif());
                }
            } catch (\Throwable) {
                // Skip validation error
            }
        } else {
            foreach ($user->getOrganizationMemberships() as $membership) {
                $org = $membership->getOrganization();
                if (!$org) {
                    continue;
                }

                foreach ($org->getCompanies() as $company) {
                    $cif = $company->getCif();
                    if (!$cif) {
                        continue;
                    }

                    try {
                        $validation = $this->eFacturaClient->validateToken((string) $cif, $anafToken->getToken());
                        if ($validation['valid'] ?? false) {
                            $anafToken->addValidatedCif($cif);
                        }
                    } catch (\Throwable) {
                        // Skip
                    }
                }
            }
        }

        $user->addAnafToken($anafToken);
        $link->setAnafToken($anafToken);
        $link->setUsedAt(new \DateTimeImmutable());

        $this->entityManager->persist($anafToken);
        $this->entityManager->flush();

        $this->eventDispatcher->dispatch(new TokenCreatedEvent($user, $anafToken), TokenCreatedEvent::NAME);

        // Notify the link creator via WebSocket
        $this->centrifugo->queue(
            'user:' . $user->getId(),
            [
                'type' => 'anaf.link_completed',
                'linkToken' => $linkToken,
            ],
        );

        return $this->renderResultPage(true, 'Token-ul ANAF a fost salvat cu succes. Puteti inchide aceasta fereastra.');
    }

    private function exchangeCodeForToken(string $code, string $clientId, string $clientSecret, string $callbackUrl): ?array
    {
        $client = HttpClient::create();

        try {
            $response = $client->request('POST', 'https://logincert.anaf.ro/anaf-oauth2/v1/token?token_content_type=jwt', [
                'body' => [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'redirect_uri' => $callbackUrl,
                    'token_content_type' => 'jwt',
                ],
            ]);

            $content = json_decode($response->getContent(false), true);

            if ($response->getStatusCode() !== 200 || !$content || !isset($content['access_token'])) {
                return null;
            }

            return $content;
        } catch (ExceptionInterface) {
            return null;
        }
    }

    private function renderResultPage(bool $success, string $message): Response
    {
        $icon = $success
            ? '<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>'
            : '<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>';

        $title = $success ? 'Conectare reusita' : 'Eroare';
        $textColor = $success ? '#16a34a' : '#dc2626';
        $escapedMessage = htmlspecialchars($message);

        return new Response(<<<HTML
<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{$title} - Storno.ro</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; background: #f9fafb; color: #111827; }
        .card { max-width: 420px; width: 100%; padding: 48px 32px; background: #fff; border-radius: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); text-align: center; }
        h1 { font-size: 22px; margin: 16px 0 8px; color: {$textColor}; }
        p { color: #6b7280; font-size: 15px; line-height: 1.5; margin: 0; }
    </style>
</head>
<body>
    <div class="card">
        {$icon}
        <h1>{$title}</h1>
        <p>{$escapedMessage}</p>
    </div>
</body>
</html>
HTML, $success ? Response::HTTP_OK : Response::HTTP_BAD_REQUEST, ['Content-Type' => 'text/html']);
    }
}
