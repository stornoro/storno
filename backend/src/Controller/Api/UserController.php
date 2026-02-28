<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Message\DeleteUserAccountMessage;
use App\Repository\OrganizationMembershipRepository;
use App\Repository\UserRepository;
use App\Security\OrganizationContext;
use App\Security\RolePermissionMap;
use App\Service\LicenseManager;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Encoder\JWTEncoderInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(path: '/api/v1')]
#[IsGranted('ROLE_USER')]
class UserController extends AbstractController
{
    private const AVATAR_ALLOWED_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
    private const AVATAR_MAX_SIZE = 5 * 1024 * 1024; // 5MB
    private const AVATAR_EXT_MAP = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public function __construct(
        private readonly OrganizationContext $organizationContext,
        private readonly LicenseManager $licenseManager,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly MessageBusInterface $messageBus,
        private readonly OrganizationMembershipRepository $membershipRepository,
        private readonly FilesystemOperator $platformStorage,
        private readonly JWTEncoderInterface $jwtEncoder,
        private readonly UserRepository $userRepository,
    ) {}

    #[Route('/me', name: 'app_api_user', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $data = [
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'firstName' => $user->getFirstName(),
            'lastName' => $user->getLastName(),
            'fullName' => $user->getFullName(),
            'phone' => $user->getPhone(),
            'locale' => $user->getLocale(),
            'timezone' => $user->getTimezone(),
            'roles' => $user->getRoles(),
            'active' => $user->isActive(),
            'emailVerified' => $user->isEmailVerified(),
            'mfaEnabled' => $user->isMfaEnabled(),
            'createdAt' => $user->getCreatedAt()?->format('c'),
            'preferences' => $user->getPreferences(),
            'avatarUrl' => $user->getAvatarPath() ? '/v1/me/avatar' : null,
        ];

        $org = $this->organizationContext->getOrganization();
        if ($org) {
            $data['organization'] = [
                'id' => (string) $org->getId(),
                'name' => $org->getName(),
                'slug' => $org->getSlug(),
                'createdAt' => $org->getCreatedAt()?->format('c'),
            ];
            $data['plan'] = $this->licenseManager->getPlanStatus($org);
        }

        // Return all active memberships for org switching
        $memberships = $this->membershipRepository->findActiveByUser($user);
        $data['memberships'] = array_map(fn($m) => [
            'id' => (string) $m->getId(),
            'role' => $m->getRole()->value,
            'organization' => [
                'id' => (string) $m->getOrganization()->getId(),
                'name' => $m->getOrganization()->getName(),
                'slug' => $m->getOrganization()->getSlug(),
            ],
        ], $memberships);

        // Return effective permissions for current org membership
        $currentMembership = $this->organizationContext->getMembership();
        if ($currentMembership) {
            $customPermissions = $currentMembership->getPermissions();
            $data['permissions'] = !empty($customPermissions)
                ? $customPermissions
                : RolePermissionMap::getPermissions($currentMembership->getRole());
            $data['currentRole'] = $currentMembership->getRole()->value;
        } else {
            $data['permissions'] = [];
            $data['currentRole'] = null;
        }

        // Check for impersonation claim in JWT
        $authHeader = $request->headers->get('Authorization', '');
        if (str_starts_with($authHeader, 'Bearer ')) {
            try {
                $payload = $this->jwtEncoder->decode(substr($authHeader, 7));
                if (!empty($payload['impersonator'])) {
                    $impersonator = $this->userRepository->find($payload['impersonator']);
                    $data['impersonating'] = true;
                    $data['impersonator'] = $impersonator ? [
                        'id' => (string) $impersonator->getId(),
                        'email' => $impersonator->getEmail(),
                        'fullName' => $impersonator->getFullName(),
                    ] : ['id' => $payload['impersonator']];
                }
            } catch (\Throwable) {
                // Ignore decode errors — normal auth flow handles validity
            }
        }

        return $this->json($data);
    }

    #[Route('/me', name: 'app_api_user_update', methods: ['PATCH'])]
    public function update(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $data = json_decode($request->getContent(), true);

        if (isset($data['firstName'])) {
            $user->setFirstName($data['firstName']);
        }
        if (isset($data['lastName'])) {
            $user->setLastName($data['lastName']);
        }
        if (isset($data['phone'])) {
            $user->setPhone($data['phone']);
        }
        if (isset($data['timezone'])) {
            $user->setTimezone($data['timezone']);
        }
        if (isset($data['preferences'])) {
            $user->setPreferences($data['preferences']);
        }

        // Password change
        if (!empty($data['currentPassword']) && !empty($data['newPassword'])) {
            if (!$this->passwordHasher->isPasswordValid($user, $data['currentPassword'])) {
                return $this->json(['error' => 'Parola curenta este incorecta.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $user->setPassword($this->passwordHasher->hashPassword($user, $data['newPassword']));
        }

        $this->entityManager->flush();

        return $this->json(['message' => 'Profile updated.']);
    }

    #[Route('/me', name: 'app_api_user_delete', methods: ['DELETE'])]
    public function delete(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $data = json_decode($request->getContent(), true);
        $password = $data['password'] ?? null;

        // Require password confirmation
        if (!$password || !$this->passwordHasher->isPasswordValid($user, $password)) {
            return $this->json(['error' => 'Parola este incorecta.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $userId = (string) $user->getId();

        // Soft-delete + deactivate immediately (blocks further API access)
        $user->softDelete($user);
        $user->setActive(false);
        // Anonymize PII
        $user->setEmail('deleted_' . $userId . '@deleted.local');
        $user->setFirstName(null);
        $user->setLastName(null);
        $user->setPhone(null);
        $user->setPassword(null);
        $user->setGoogleId(null);
        $user->setAppleId(null);
        $user->setMicrosoftId(null);
        $user->setTelegramChatId(null);
        $user->setPreferences(null);

        // Delete avatar file if exists
        if ($user->getAvatarPath()) {
            try {
                $this->platformStorage->delete($user->getAvatarPath());
            } catch (\Throwable) {}
            $user->setAvatarPath(null);
        }

        $this->entityManager->flush();

        // Dispatch async handler for full cascade deletion
        $this->messageBus->dispatch(new DeleteUserAccountMessage($userId));

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/me/avatar', name: 'app_api_user_avatar_upload', methods: ['POST'])]
    public function uploadAvatar(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $file = $request->files->get('avatar');

        if (!$file) {
            return $this->json(['error' => 'No file uploaded.'], Response::HTTP_BAD_REQUEST);
        }

        if (!in_array($file->getMimeType(), self::AVATAR_ALLOWED_TYPES, true)) {
            return $this->json(['error' => 'Invalid file type. Allowed: jpg, png, webp.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($file->getSize() > self::AVATAR_MAX_SIZE) {
            return $this->json(['error' => 'File too large. Max 5MB.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Delete old avatar if exists
        $oldPath = $user->getAvatarPath();
        if ($oldPath) {
            try {
                $this->platformStorage->delete($oldPath);
            } catch (\Throwable) {}
        }

        $ext = self::AVATAR_EXT_MAP[$file->getMimeType()] ?? 'jpg';
        $path = 'avatars/' . $user->getId() . '.' . $ext;

        $stream = fopen($file->getPathname(), 'r');
        $this->platformStorage->writeStream($path, $stream);
        if (is_resource($stream)) {
            fclose($stream);
        }

        $user->setAvatarPath($path);
        $this->entityManager->flush();

        return $this->json(['avatarUrl' => '/v1/me/avatar']);
    }

    #[Route('/me/avatar', name: 'app_api_user_avatar_delete', methods: ['DELETE'])]
    public function deleteAvatar(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        if ($user->getAvatarPath()) {
            try {
                $this->platformStorage->delete($user->getAvatarPath());
            } catch (\Throwable) {}
            $user->setAvatarPath(null);
            $this->entityManager->flush();
        }

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/me/avatar', name: 'app_api_user_avatar_serve', methods: ['GET'])]
    public function serveAvatar(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $path = $user->getAvatarPath();

        if (!$path || !$this->platformStorage->fileExists($path)) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $mimeType = $this->platformStorage->mimeType($path);
        $stream = $this->platformStorage->readStream($path);

        $response = new StreamedResponse(function () use ($stream) {
            fpassthru($stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
        });

        $response->headers->set('Content-Type', $mimeType);
        $response->headers->set('Cache-Control', 'private, max-age=3600');

        return $response;
    }
}
