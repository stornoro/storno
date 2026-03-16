<?php

namespace App\Controller\Frontend;

use App\Entity\AnafToken;
use App\Entity\User;
use App\Events\Anaf\TokenCreatedEvent;
use App\Manager\UserManager;
use App\Repository\AnafTokenLinkRepository;
use App\Service\Anaf\EFacturaClient;
use App\Service\Centrifugo\CentrifugoService;
use App\Service\Storage\CredentialEncryptor;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

#[Route(path: '/api')]
class ANAFController extends AbstractController
{
    public function __construct(
        private readonly AnafTokenLinkRepository $anafTokenLinkRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly CentrifugoService $centrifugo,
        private readonly EFacturaClient $eFacturaClient,
        private readonly FilesystemOperator $defaultStorage,
        private readonly CredentialEncryptor $credentialEncryptor,
    ) {}

    #[Route(path: '/connect/anaf', name: 'connect_anaf_start', methods: ['GET'])]
    public function connectAction(Request $request): RedirectResponse
    {
        $clientId = $this->getParameter('app.anaf_oauth2_client_id');
        $callbackUrl = $this->getParameter('app.anaf_oauth2_redirect_uri');

        // If link token is provided, pass it through OAuth state parameter
        $linkToken = $request->query->get('link');
        $state = $linkToken ? json_encode(['link' => $linkToken]) : '';

        $redirectUrl = sprintf(
            'https://logincert.anaf.ro/anaf-oauth2/v1/authorize?response_type=code&client_id=%s&redirect_uri=%s&token_content_type=jwt%s',
            $clientId,
            $callbackUrl,
            $state ? '&state=' . urlencode($state) : '',
        );

        return $this->redirect($redirectUrl);
    }

    #[Route(path: '/account/anaf', name: 'update_anaf_token', methods: ['PATCH'])]
    #[IsGranted('ROLE_USER')]
    public function connectCheckAction(Request $request, UserManager $userManager, EventDispatcherInterface $event): Response
    {
        $clientId = $this->getParameter('app.anaf_oauth2_client_id');
        $clientSecret = $this->getParameter('app.anaf_oauth2_client_secret');
        $callbackUrl = $this->getParameter('app.anaf_oauth2_redirect_uri');

        /** @var User $user */
        $user = $this->getUser();
        $code = $request->query->get('code');

        $content = $this->exchangeCodeForToken($code, $clientId, $clientSecret, $callbackUrl);
        if ($content === null) {
            return $this->json([
                'status' => 'fail',
                'message' => 'Nu s-a obtinut token-ul, va rugam incercati din nou. Asigurati-va ca aveti dispozitivul in calculator.',
            ], Response::HTTP_BAD_GATEWAY);
        }

        $anafToken = new AnafToken();
        $anafToken
            ->setToken($content['access_token'])
            ->setRefreshToken($content['refresh_token'])
            ->setExpireAt(new \DateTimeImmutable(sprintf('+%s seconds', $content['expires_in'])));

        $this->validateTokenForUserCompanies($user, $anafToken);
        $user->addAnafToken($anafToken);

        try {
            $userManager->update($user);
            $event->dispatch(new TokenCreatedEvent($user, $anafToken), TokenCreatedEvent::NAME);

            // Notify via WebSocket
            $this->centrifugo->queue(
                'user:' . $user->getId(),
                [
                    'type' => 'anaf.token_created',
                    'tokenId' => (string) $anafToken->getId(),
                ],
            );

            return $this->json([
                'status' => 'ok',
                'message' => 'Token-ul a fost salvat in contul tau.',
                'data' => [
                    'id' => (string) $anafToken->getId(),
                    'expiresAt' => $anafToken->getExpireAt()->format('c'),
                ],
            ], Response::HTTP_CREATED);
        } catch (\Exception) {
            return $this->json(['status' => 'fail', 'message' => 'A aparut o eroare la salvarea token-ului. Incercati din nou.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route(path: '/anaf/link-callback', name: 'anaf_link_callback', methods: ['GET'])]
    public function linkCallbackAction(Request $request, EventDispatcherInterface $event): Response
    {
        $code = $request->query->get('code');
        $stateParam = $request->query->get('state', '');

        $state = json_decode($stateParam, true);
        $linkToken = $state['link'] ?? null;

        if (!$linkToken || !$code) {
            return $this->json([
                'status' => 'fail',
                'message' => 'Link invalid sau expirat.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $link = $this->anafTokenLinkRepository->findValidByToken($linkToken);
        if (!$link) {
            return $this->json([
                'status' => 'fail',
                'message' => 'Link-ul a expirat sau a fost deja utilizat.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $clientId = $this->getParameter('app.anaf_oauth2_client_id');
        $clientSecret = $this->getParameter('app.anaf_oauth2_client_secret');
        $callbackUrl = $this->getParameter('app.anaf_oauth2_redirect_uri');

        $content = $this->exchangeCodeForToken($code, $clientId, $clientSecret, $callbackUrl);
        if ($content === null) {
            return $this->json([
                'status' => 'fail',
                'message' => 'Nu s-a obtinut token-ul de la ANAF.',
            ], Response::HTTP_BAD_GATEWAY);
        }

        $user = $link->getUser();

        $anafToken = new AnafToken();
        $anafToken
            ->setToken($content['access_token'])
            ->setRefreshToken($content['refresh_token'])
            ->setExpireAt(new \DateTimeImmutable(sprintf('+%s seconds', $content['expires_in'])));

        // Validate against the link's company CIF, or fall back to all user companies
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
            $this->validateTokenForUserCompanies($user, $anafToken);
        }

        $user->addAnafToken($anafToken);
        $link->setAnafToken($anafToken);
        $link->setUsedAt(new \DateTimeImmutable());

        $this->entityManager->persist($anafToken);
        $this->entityManager->flush();

        $event->dispatch(new TokenCreatedEvent($user, $anafToken), TokenCreatedEvent::NAME);

        // Notify the link creator via WebSocket
        $this->centrifugo->queue(
            'user:' . $user->getId(),
            [
                'type' => 'anaf.link_completed',
                'linkToken' => $linkToken,
            ],
        );

        return $this->json([
            'status' => 'ok',
            'message' => 'Token-ul ANAF a fost salvat cu succes.',
        ]);
    }

    #[Route(path: '/account/anaf/{id}/certificate', name: 'anaf_upload_certificate', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function uploadCertificate(string $id, Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $anafToken = $this->findUserToken($user, $id);

        if ($anafToken === null) {
            return $this->json(['error' => 'Token not found.'], Response::HTTP_NOT_FOUND);
        }

        $file = $request->files->get('file');
        $password = $request->request->get('password', '');

        if ($file === null) {
            return $this->json(['error' => 'No certificate file provided.'], Response::HTTP_BAD_REQUEST);
        }

        $ext = strtolower($file->getClientOriginalExtension());
        if (!in_array($ext, ['pfx', 'p12'], true)) {
            return $this->json(['error' => 'Invalid file type. Only .pfx and .p12 files are accepted.'], Response::HTTP_BAD_REQUEST);
        }

        // Validate the certificate with the provided password
        $pfxContent = file_get_contents($file->getPathname());
        $certs = [];
        if (!openssl_pkcs12_read($pfxContent, $certs, $password)) {
            return $this->json(['error' => 'Invalid certificate or wrong password.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Store in S3
        $storagePath = sprintf('anaf-certs/%s/%s.pfx', $user->getId(), $anafToken->getId());
        $this->defaultStorage->write($storagePath, $pfxContent);

        // Encrypt password
        $encryptedPassword = $this->credentialEncryptor->encrypt(['password' => $password]);

        $anafToken->setCertificatePath($storagePath);
        $anafToken->setCertificatePassword($encryptedPassword);
        $this->entityManager->flush();

        return $this->json([
            'status' => 'ok',
            'message' => 'Certificate uploaded successfully.',
            'hasCertificate' => true,
        ]);
    }

    #[Route(path: '/account/anaf/{id}/certificate', name: 'anaf_delete_certificate', methods: ['DELETE'])]
    #[IsGranted('ROLE_USER')]
    public function deleteCertificate(string $id): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $anafToken = $this->findUserToken($user, $id);

        if ($anafToken === null) {
            return $this->json(['error' => 'Token not found.'], Response::HTTP_NOT_FOUND);
        }

        if ($anafToken->getCertificatePath() !== null) {
            try {
                $this->defaultStorage->delete($anafToken->getCertificatePath());
            } catch (\Throwable) {
                // File may already be gone
            }
        }

        $anafToken->setCertificatePath(null);
        $anafToken->setCertificatePassword(null);
        $this->entityManager->flush();

        return $this->json([
            'status' => 'ok',
            'message' => 'Certificate removed.',
            'hasCertificate' => false,
        ]);
    }

    private function findUserToken(User $user, string $tokenId): ?AnafToken
    {
        foreach ($user->getAnafTokens() as $token) {
            if ((string) $token->getId() === $tokenId) {
                return $token;
            }
        }

        return null;
    }

    /**
     * Validate the token against all companies in the user's organizations and add valid CIFs.
     */
    private function validateTokenForUserCompanies(User $user, AnafToken $anafToken): void
    {
        foreach ($user->getOrganizationMemberships() as $membership) {
            $org = $membership->getOrganization();
            if (!$org) continue;

            foreach ($org->getCompanies() as $company) {
                $cif = $company->getCif();
                if (!$cif) continue;

                try {
                    $validation = $this->eFacturaClient->validateToken((string) $cif, $anafToken->getToken());
                    if ($validation['valid'] ?? false) {
                        $anafToken->addValidatedCif($cif);
                    }
                } catch (\Throwable) {
                    // Skip validation errors for individual CIFs
                }
            }
        }
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

            if ($response->getStatusCode() !== Response::HTTP_OK || !$content || !isset($content['access_token'])) {
                return null;
            }

            return $content;
        } catch (ExceptionInterface $ex) {
            return null;
        }
    }
}
