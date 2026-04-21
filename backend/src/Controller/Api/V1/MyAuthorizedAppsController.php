<?php

namespace App\Controller\Api\V1;

use App\Entity\User;
use App\Repository\OAuth2AccessTokenRepository;
use App\Repository\OAuth2ClientRepository;
use App\Repository\OAuth2RefreshTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Per-user view of OAuth2 apps the *current* user has authorized.
 * Separate from OAuth2ClientController, which is for org admins managing
 * the apps they own — here the user only sees apps they've personally
 * granted access to, and can revoke their own tokens for those apps.
 */
#[Route('/api/v1/me/authorized-apps')]
class MyAuthorizedAppsController extends AbstractController
{
    public function __construct(
        private readonly OAuth2AccessTokenRepository $accessTokenRepository,
        private readonly OAuth2RefreshTokenRepository $refreshTokenRepository,
        private readonly OAuth2ClientRepository $clientRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    #[Route('', methods: ['GET'])]
    public function index(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Not authenticated.'], Response::HTTP_UNAUTHORIZED);
        }

        $entries = $this->accessTokenRepository->findAuthorizedClientsForUser($user);

        $data = array_map(function (array $entry) {
            $client = $entry['client'];
            return [
                'clientUuid' => $client->getId()->toRfc4122(),
                'name' => $client->getName(),
                'description' => $client->getDescription(),
                'logoUrl' => $client->getLogoUrl(),
                'scopes' => $entry['scopes'],
                'lastActiveAt' => $entry['lastActiveAt']->format('c'),
            ];
        }, $entries);

        return $this->json(['data' => $data]);
    }

    #[Route('/{uuid}', methods: ['DELETE'])]
    public function revoke(string $uuid): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Not authenticated.'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $client = $this->clientRepository->find(Uuid::fromString($uuid));
        } catch (\Throwable) {
            return $this->json(['error' => 'Invalid app identifier.'], Response::HTTP_BAD_REQUEST);
        }

        if (!$client) {
            return $this->json(['error' => 'Authorized app not found.'], Response::HTTP_NOT_FOUND);
        }

        $accessRevoked = $this->accessTokenRepository->revokeAllForUserAndClient($user, $client);
        $refreshRevoked = $this->refreshTokenRepository->revokeAllForUserAndClient($user, $client);

        // No active tokens existed → treat as not-found so we don't leak existence
        // of apps the user never authorized.
        if ($accessRevoked === 0 && $refreshRevoked === 0) {
            return $this->json(['error' => 'Authorized app not found.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }
}
