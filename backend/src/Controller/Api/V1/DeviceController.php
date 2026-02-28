<?php

namespace App\Controller\Api\V1;

use App\Entity\User;
use App\Entity\UserDevice;
use App\Repository\UserDeviceRepository;
use App\Security\OrganizationContext;
use App\Service\LicenseManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/devices')]
class DeviceController extends AbstractController
{
    public function __construct(
        private readonly UserDeviceRepository $deviceRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly OrganizationContext $organizationContext,
        private readonly LicenseManager $licenseManager,
    ) {}

    #[Route('', methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $org = $this->organizationContext->getOrganization();
        if ($org && !$this->licenseManager->canUseMobileApp($org)) {
            return $this->json([
                'error' => 'Mobile app is not available on your plan.',
                'code' => 'PLAN_LIMIT',
            ], Response::HTTP_PAYMENT_REQUIRED);
        }

        $data = json_decode($request->getContent(), true);
        $token = $data['token'] ?? null;
        $platform = $data['platform'] ?? null;

        if (!$token || !$platform) {
            return $this->json(['error' => 'Token and platform are required.'], Response::HTTP_BAD_REQUEST);
        }

        if (!in_array($platform, ['ios', 'android', 'web'], true)) {
            return $this->json(['error' => 'Invalid platform.'], Response::HTTP_BAD_REQUEST);
        }

        $existing = $this->deviceRepository->findByToken($token);
        if ($existing) {
            $existing->setUser($user);
            $existing->setLastUsedAt(new \DateTimeImmutable());
            $this->entityManager->flush();

            return $this->json(['status' => 'updated']);
        }

        $device = new UserDevice();
        $device->setUser($user);
        $device->setToken($token);
        $device->setPlatform($platform);

        $this->entityManager->persist($device);
        $this->entityManager->flush();

        return $this->json(['status' => 'registered'], Response::HTTP_CREATED);
    }

    #[Route('', methods: ['DELETE'])]
    public function unregister(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $data = json_decode($request->getContent(), true);
        $token = $data['token'] ?? null;

        if (!$token) {
            return $this->json(['error' => 'Token is required.'], Response::HTTP_BAD_REQUEST);
        }

        $this->deviceRepository->removeByToken($user, $token);

        return $this->json(['status' => 'removed']);
    }
}
