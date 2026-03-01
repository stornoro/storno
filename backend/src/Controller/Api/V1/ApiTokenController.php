<?php

namespace App\Controller\Api\V1;

use App\Entity\ApiToken;
use App\Repository\ApiTokenRepository;
use App\Security\OrganizationContext;
use App\Security\Permission;
use App\Security\RolePermissionMap;
use App\Service\ApiTokenService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1')]
class ApiTokenController extends AbstractController
{
    public function __construct(
        private readonly ApiTokenRepository $apiTokenRepository,
        private readonly OrganizationContext $organizationContext,
        private readonly EntityManagerInterface $entityManager,
        private readonly ApiTokenService $apiTokenService,
    ) {}

    #[Route('/api-tokens', methods: ['GET'])]
    public function index(): JsonResponse
    {
        if (!$this->organizationContext->hasPermission(Permission::API_KEY_VIEW)) {
            return $this->json(['error' => 'Permission denied'], Response::HTTP_FORBIDDEN);
        }

        $user = $this->getUser();
        $org = $this->organizationContext->getOrganization();

        $tokens = $this->apiTokenRepository->findByUser($user, $org);

        return $this->json(['data' => $tokens], context: ['groups' => ['api_token:read']]);
    }

    #[Route('/api-tokens', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        if ($this->organizationContext->isTokenAuth()) {
            return $this->json(['error' => 'API keys cannot be managed via programmatic tokens. Use a browser session.'], Response::HTTP_FORBIDDEN);
        }

        if (!$this->organizationContext->hasPermission(Permission::API_KEY_MANAGE)) {
            return $this->json(['error' => 'Permission denied'], Response::HTTP_FORBIDDEN);
        }

        $user = $this->getUser();
        $org = $this->organizationContext->getOrganization();

        if (!$org) {
            return $this->json(['error' => 'Organization not found.'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);

        $name = trim($data['name'] ?? '');
        if (!$name) {
            return $this->json(['error' => 'Field "name" is required.'], Response::HTTP_BAD_REQUEST);
        }

        $scopes = $data['scopes'] ?? [];
        if (!is_array($scopes) || empty($scopes)) {
            return $this->json(['error' => 'At least one scope is required.'], Response::HTTP_BAD_REQUEST);
        }

        // Validate all scopes are valid Permission constants
        $allPermissions = Permission::all();
        $invalidScopes = array_diff($scopes, $allPermissions);
        if (!empty($invalidScopes)) {
            return $this->json([
                'error' => 'Invalid scopes: ' . implode(', ', $invalidScopes),
            ], Response::HTTP_BAD_REQUEST);
        }

        // Ensure scopes don't exceed user's actual permissions
        foreach ($scopes as $scope) {
            if (!$this->organizationContext->hasPermission($scope)) {
                return $this->json([
                    'error' => 'Cannot grant scope "' . $scope . '" — you do not have this permission.',
                ], Response::HTTP_FORBIDDEN);
            }
        }

        // Parse expiry
        $expireAt = null;
        if (!empty($data['expiresAt'])) {
            try {
                $expireAt = new \DateTimeImmutable($data['expiresAt']);
            } catch (\Exception) {
                return $this->json(['error' => 'Invalid expiresAt date format.'], Response::HTTP_BAD_REQUEST);
            }
        }

        // Generate token
        $tokenData = $this->apiTokenService->generateToken();

        $apiToken = new ApiToken();
        $apiToken->setUser($user);
        $apiToken->setOrganization($org);
        $apiToken->setName($name);
        $apiToken->setTokenHash($tokenData['hash']);
        $apiToken->setTokenPrefix($tokenData['prefix']);
        $apiToken->setScopes($scopes);
        $apiToken->setExpireAt($expireAt);

        $this->entityManager->persist($apiToken);
        $this->entityManager->flush();

        // Return the serialized token plus the raw token (shown only once)
        $response = $this->json($apiToken, Response::HTTP_CREATED, context: ['groups' => ['api_token:read']]);
        $responseData = json_decode($response->getContent(), true);
        $responseData['token'] = $tokenData['raw'];

        return new JsonResponse($responseData, Response::HTTP_CREATED);
    }

    #[Route('/api-tokens/{uuid}', methods: ['PATCH'])]
    public function update(string $uuid, Request $request): JsonResponse
    {
        if ($this->organizationContext->isTokenAuth()) {
            return $this->json(['error' => 'API keys cannot be managed via programmatic tokens. Use a browser session.'], Response::HTTP_FORBIDDEN);
        }

        if (!$this->organizationContext->hasPermission(Permission::API_KEY_MANAGE)) {
            return $this->json(['error' => 'Permission denied'], Response::HTTP_FORBIDDEN);
        }

        $apiToken = $this->apiTokenRepository->find($uuid);
        if (!$apiToken) {
            return $this->json(['error' => 'API token not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->denyAccessUnlessGranted('edit', $apiToken);

        $data = json_decode($request->getContent(), true);

        if (isset($data['name'])) {
            $name = trim($data['name']);
            if (!$name) {
                return $this->json(['error' => 'Name cannot be empty.'], Response::HTTP_BAD_REQUEST);
            }
            $apiToken->setName($name);
        }

        if (isset($data['scopes'])) {
            $scopes = $data['scopes'];
            if (!is_array($scopes) || empty($scopes)) {
                return $this->json(['error' => 'At least one scope is required.'], Response::HTTP_BAD_REQUEST);
            }

            $allPermissions = Permission::all();
            $invalidScopes = array_diff($scopes, $allPermissions);
            if (!empty($invalidScopes)) {
                return $this->json([
                    'error' => 'Invalid scopes: ' . implode(', ', $invalidScopes),
                ], Response::HTTP_BAD_REQUEST);
            }

            foreach ($scopes as $scope) {
                if (!$this->organizationContext->hasPermission($scope)) {
                    return $this->json([
                        'error' => 'Cannot grant scope "' . $scope . '" — you do not have this permission.',
                    ], Response::HTTP_FORBIDDEN);
                }
            }

            $apiToken->setScopes($scopes);
        }

        $apiToken->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        return $this->json($apiToken, context: ['groups' => ['api_token:read']]);
    }

    #[Route('/api-tokens/{uuid}', methods: ['DELETE'])]
    public function revoke(string $uuid): JsonResponse
    {
        if ($this->organizationContext->isTokenAuth()) {
            return $this->json(['error' => 'API keys cannot be managed via programmatic tokens. Use a browser session.'], Response::HTTP_FORBIDDEN);
        }

        if (!$this->organizationContext->hasPermission(Permission::API_KEY_MANAGE)) {
            return $this->json(['error' => 'Permission denied'], Response::HTTP_FORBIDDEN);
        }

        $apiToken = $this->apiTokenRepository->find($uuid);
        if (!$apiToken) {
            return $this->json(['error' => 'API token not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->denyAccessUnlessGranted('edit', $apiToken);

        $apiToken->setRevokedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/api-tokens/{uuid}/rotate', methods: ['POST'])]
    public function rotate(string $uuid): JsonResponse
    {
        if ($this->organizationContext->isTokenAuth()) {
            return $this->json(['error' => 'API keys cannot be managed via programmatic tokens. Use a browser session.'], Response::HTTP_FORBIDDEN);
        }

        if (!$this->organizationContext->hasPermission(Permission::API_KEY_MANAGE)) {
            return $this->json(['error' => 'Permission denied'], Response::HTTP_FORBIDDEN);
        }

        $oldToken = $this->apiTokenRepository->find($uuid);
        if (!$oldToken) {
            return $this->json(['error' => 'API token not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->denyAccessUnlessGranted('edit', $oldToken);

        if ($oldToken->getRevokedAt()) {
            return $this->json(['error' => 'Cannot rotate a revoked token.'], Response::HTTP_BAD_REQUEST);
        }

        // Revoke old token
        $oldToken->setRevokedAt(new \DateTimeImmutable());

        // Generate new token with same metadata
        $tokenData = $this->apiTokenService->generateToken();

        $newToken = new ApiToken();
        $newToken->setUser($oldToken->getUser());
        $newToken->setOrganization($oldToken->getOrganization());
        $newToken->setName($oldToken->getName());
        $newToken->setTokenHash($tokenData['hash']);
        $newToken->setTokenPrefix($tokenData['prefix']);
        $newToken->setScopes($oldToken->getScopes());
        $newToken->setExpireAt($oldToken->getExpireAt());

        $this->entityManager->persist($newToken);
        $this->entityManager->flush();

        $response = $this->json($newToken, Response::HTTP_CREATED, context: ['groups' => ['api_token:read']]);
        $responseData = json_decode($response->getContent(), true);
        $responseData['token'] = $tokenData['raw'];

        return new JsonResponse($responseData, Response::HTTP_CREATED);
    }

    #[Route('/api-tokens/scopes', methods: ['GET'])]
    public function scopes(): JsonResponse
    {
        if (!$this->organizationContext->hasPermission(Permission::API_KEY_VIEW)) {
            return $this->json(['error' => 'Permission denied'], Response::HTTP_FORBIDDEN);
        }

        $allPermissions = Permission::all();

        // Group permissions by category and filter to user's actual permissions
        $scopes = [];
        foreach ($allPermissions as $permission) {
            if (!$this->organizationContext->hasPermission($permission)) {
                continue;
            }

            $parts = explode('.', $permission);
            $category = $parts[0] ?? 'other';

            $scopes[] = [
                'value' => $permission,
                'label' => $permission,
                'category' => $category,
            ];
        }

        return $this->json(['scopes' => $scopes]);
    }
}
