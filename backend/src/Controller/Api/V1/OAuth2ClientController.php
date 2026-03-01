<?php

namespace App\Controller\Api\V1;

use App\Entity\OAuth2Client;
use App\Repository\OAuth2AccessTokenRepository;
use App\Repository\OAuth2ClientRepository;
use App\Repository\OAuth2RefreshTokenRepository;
use App\Security\OrganizationContext;
use App\Security\Permission;
use App\Service\OAuth2TokenService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/oauth2')]
class OAuth2ClientController extends AbstractController
{
    public function __construct(
        private readonly OAuth2ClientRepository $clientRepository,
        private readonly OAuth2AccessTokenRepository $accessTokenRepository,
        private readonly OAuth2RefreshTokenRepository $refreshTokenRepository,
        private readonly OrganizationContext $organizationContext,
        private readonly EntityManagerInterface $entityManager,
        private readonly OAuth2TokenService $tokenService,
    ) {}

    #[Route('/clients', methods: ['GET'])]
    public function index(): JsonResponse
    {
        if (!$this->organizationContext->hasPermission(Permission::OAUTH2_APP_VIEW)) {
            return $this->json(['error' => 'Permission denied'], Response::HTTP_FORBIDDEN);
        }

        $org = $this->organizationContext->getOrganization();
        if (!$org) {
            return $this->json(['error' => 'Organization not found.'], Response::HTTP_NOT_FOUND);
        }

        $clients = $this->clientRepository->findByOrganization($org);

        return $this->json(['data' => $clients], context: ['groups' => ['oauth2_client:read']]);
    }

    #[Route('/clients', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        if ($this->organizationContext->isTokenAuth()) {
            return $this->json(['error' => 'OAuth2 apps cannot be managed via programmatic tokens. Use a browser session.'], Response::HTTP_FORBIDDEN);
        }

        if (!$this->organizationContext->hasPermission(Permission::OAUTH2_APP_MANAGE)) {
            return $this->json(['error' => 'Permission denied'], Response::HTTP_FORBIDDEN);
        }

        $org = $this->organizationContext->getOrganization();
        if (!$org) {
            return $this->json(['error' => 'Organization not found.'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);

        $name = trim($data['name'] ?? '');
        if (!$name) {
            return $this->json(['error' => 'Field "name" is required.'], Response::HTTP_BAD_REQUEST);
        }

        $clientType = $data['clientType'] ?? 'confidential';
        if (!in_array($clientType, ['confidential', 'public'], true)) {
            return $this->json(['error' => 'clientType must be "confidential" or "public".'], Response::HTTP_BAD_REQUEST);
        }

        $redirectUris = $data['redirectUris'] ?? [];
        if (!is_array($redirectUris) || empty($redirectUris)) {
            return $this->json(['error' => 'At least one redirect URI is required.'], Response::HTTP_BAD_REQUEST);
        }

        foreach ($redirectUris as $uri) {
            if (!filter_var($uri, FILTER_VALIDATE_URL) && !str_starts_with($uri, 'http://localhost')) {
                return $this->json(['error' => 'Invalid redirect URI: ' . $uri], Response::HTTP_BAD_REQUEST);
            }
        }

        $scopes = $data['scopes'] ?? [];
        if (!is_array($scopes) || empty($scopes)) {
            return $this->json(['error' => 'At least one scope is required.'], Response::HTTP_BAD_REQUEST);
        }

        $allPermissions = Permission::all();
        $invalidScopes = array_diff($scopes, $allPermissions);
        if (!empty($invalidScopes)) {
            return $this->json(['error' => 'Invalid scopes: ' . implode(', ', $invalidScopes)], Response::HTTP_BAD_REQUEST);
        }

        foreach ($scopes as $scope) {
            if (!$this->organizationContext->hasPermission($scope)) {
                return $this->json([
                    'error' => 'Cannot grant scope "' . $scope . '" — you do not have this permission.',
                ], Response::HTTP_FORBIDDEN);
            }
        }

        $clientId = $this->tokenService->generateClientId();

        $client = new OAuth2Client();
        $client->setOrganization($org);
        $client->setCreatedBy($this->getUser());
        $client->setName($name);
        $client->setDescription($data['description'] ?? null);
        $client->setClientId($clientId);
        $client->setClientType($clientType);
        $client->setRedirectUris($redirectUris);
        $client->setScopes($scopes);
        $client->setWebsiteUrl($data['websiteUrl'] ?? null);
        $client->setLogoUrl($data['logoUrl'] ?? null);

        $rawSecret = null;
        if ($clientType === 'confidential') {
            $secretData = $this->tokenService->generateClientSecret();
            $client->setClientSecretHash($secretData['hash']);
            $client->setClientSecretPrefix($secretData['prefix']);
            $rawSecret = $secretData['raw'];
        }

        $this->entityManager->persist($client);
        $this->entityManager->flush();

        $response = $this->json($client, Response::HTTP_CREATED, context: ['groups' => ['oauth2_client:read']]);
        $responseData = json_decode($response->getContent(), true);

        if ($rawSecret) {
            $responseData['clientSecret'] = $rawSecret;
        }

        return new JsonResponse($responseData, Response::HTTP_CREATED);
    }

    #[Route('/clients/{uuid}', methods: ['GET'])]
    public function show(string $uuid): JsonResponse
    {
        if (!$this->organizationContext->hasPermission(Permission::OAUTH2_APP_VIEW)) {
            return $this->json(['error' => 'Permission denied'], Response::HTTP_FORBIDDEN);
        }

        $client = $this->clientRepository->find($uuid);
        if (!$client) {
            return $this->json(['error' => 'OAuth2 app not found.'], Response::HTTP_NOT_FOUND);
        }

        $org = $this->organizationContext->getOrganization();
        if ($client->getOrganization() !== $org) {
            return $this->json(['error' => 'OAuth2 app not found.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json(['data' => $client], context: ['groups' => ['oauth2_client:read']]);
    }

    #[Route('/clients/{uuid}', methods: ['PATCH'])]
    public function update(string $uuid, Request $request): JsonResponse
    {
        if ($this->organizationContext->isTokenAuth()) {
            return $this->json(['error' => 'OAuth2 apps cannot be managed via programmatic tokens. Use a browser session.'], Response::HTTP_FORBIDDEN);
        }

        if (!$this->organizationContext->hasPermission(Permission::OAUTH2_APP_MANAGE)) {
            return $this->json(['error' => 'Permission denied'], Response::HTTP_FORBIDDEN);
        }

        $client = $this->clientRepository->find($uuid);
        if (!$client) {
            return $this->json(['error' => 'OAuth2 app not found.'], Response::HTTP_NOT_FOUND);
        }

        $org = $this->organizationContext->getOrganization();
        if ($client->getOrganization() !== $org) {
            return $this->json(['error' => 'OAuth2 app not found.'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);

        if (isset($data['name'])) {
            $name = trim($data['name']);
            if (!$name) {
                return $this->json(['error' => 'Name cannot be empty.'], Response::HTTP_BAD_REQUEST);
            }
            $client->setName($name);
        }

        if (isset($data['description'])) {
            $client->setDescription($data['description']);
        }

        if (isset($data['redirectUris'])) {
            $redirectUris = $data['redirectUris'];
            if (!is_array($redirectUris) || empty($redirectUris)) {
                return $this->json(['error' => 'At least one redirect URI is required.'], Response::HTTP_BAD_REQUEST);
            }
            foreach ($redirectUris as $uri) {
                if (!filter_var($uri, FILTER_VALIDATE_URL) && !str_starts_with($uri, 'http://localhost')) {
                    return $this->json(['error' => 'Invalid redirect URI: ' . $uri], Response::HTTP_BAD_REQUEST);
                }
            }
            $client->setRedirectUris($redirectUris);
        }

        if (isset($data['scopes'])) {
            $scopes = $data['scopes'];
            if (!is_array($scopes) || empty($scopes)) {
                return $this->json(['error' => 'At least one scope is required.'], Response::HTTP_BAD_REQUEST);
            }

            $allPermissions = Permission::all();
            $invalidScopes = array_diff($scopes, $allPermissions);
            if (!empty($invalidScopes)) {
                return $this->json(['error' => 'Invalid scopes: ' . implode(', ', $invalidScopes)], Response::HTTP_BAD_REQUEST);
            }

            foreach ($scopes as $scope) {
                if (!$this->organizationContext->hasPermission($scope)) {
                    return $this->json([
                        'error' => 'Cannot grant scope "' . $scope . '" — you do not have this permission.',
                    ], Response::HTTP_FORBIDDEN);
                }
            }

            $client->setScopes($scopes);
        }

        if (isset($data['isActive'])) {
            $client->setIsActive((bool) $data['isActive']);
        }

        if (isset($data['websiteUrl'])) {
            $client->setWebsiteUrl($data['websiteUrl']);
        }

        if (isset($data['logoUrl'])) {
            $client->setLogoUrl($data['logoUrl']);
        }

        $client->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        return $this->json(['data' => $client], context: ['groups' => ['oauth2_client:read']]);
    }

    #[Route('/clients/{uuid}', methods: ['DELETE'])]
    public function revoke(string $uuid): JsonResponse
    {
        if ($this->organizationContext->isTokenAuth()) {
            return $this->json(['error' => 'OAuth2 apps cannot be managed via programmatic tokens. Use a browser session.'], Response::HTTP_FORBIDDEN);
        }

        if (!$this->organizationContext->hasPermission(Permission::OAUTH2_APP_MANAGE)) {
            return $this->json(['error' => 'Permission denied'], Response::HTTP_FORBIDDEN);
        }

        $client = $this->clientRepository->find($uuid);
        if (!$client) {
            return $this->json(['error' => 'OAuth2 app not found.'], Response::HTTP_NOT_FOUND);
        }

        $org = $this->organizationContext->getOrganization();
        if ($client->getOrganization() !== $org) {
            return $this->json(['error' => 'OAuth2 app not found.'], Response::HTTP_NOT_FOUND);
        }

        $client->setRevokedAt(new \DateTimeImmutable());
        $client->setIsActive(false);

        // Revoke all tokens for this client
        $this->accessTokenRepository->revokeAllForClient($client);
        $this->refreshTokenRepository->revokeAllForClient($client);

        $this->entityManager->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/clients/{uuid}/rotate-secret', methods: ['POST'])]
    public function rotateSecret(string $uuid): JsonResponse
    {
        if ($this->organizationContext->isTokenAuth()) {
            return $this->json(['error' => 'OAuth2 apps cannot be managed via programmatic tokens. Use a browser session.'], Response::HTTP_FORBIDDEN);
        }

        if (!$this->organizationContext->hasPermission(Permission::OAUTH2_APP_MANAGE)) {
            return $this->json(['error' => 'Permission denied'], Response::HTTP_FORBIDDEN);
        }

        $client = $this->clientRepository->find($uuid);
        if (!$client) {
            return $this->json(['error' => 'OAuth2 app not found.'], Response::HTTP_NOT_FOUND);
        }

        $org = $this->organizationContext->getOrganization();
        if ($client->getOrganization() !== $org) {
            return $this->json(['error' => 'OAuth2 app not found.'], Response::HTTP_NOT_FOUND);
        }

        if ($client->isRevoked()) {
            return $this->json(['error' => 'Cannot rotate secret of a revoked app.'], Response::HTTP_BAD_REQUEST);
        }

        if ($client->isPublic()) {
            return $this->json(['error' => 'Public clients do not have a secret.'], Response::HTTP_BAD_REQUEST);
        }

        $secretData = $this->tokenService->generateClientSecret();
        $client->setClientSecretHash($secretData['hash']);
        $client->setClientSecretPrefix($secretData['prefix']);
        $client->setUpdatedAt(new \DateTimeImmutable());

        $this->entityManager->flush();

        $response = $this->json(['data' => $client], context: ['groups' => ['oauth2_client:read']]);
        $responseData = json_decode($response->getContent(), true);
        $responseData['clientSecret'] = $secretData['raw'];

        return new JsonResponse($responseData);
    }
}
