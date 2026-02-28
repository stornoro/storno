<?php

namespace App\Controller\Api\V1;

use App\Entity\EmailUnsubscribe;
use App\Entity\NotificationPreference;
use App\Entity\User;
use App\Service\EmailUnsubscribeService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class UnsubscribeController extends AbstractController
{
    public function __construct(
        private readonly EmailUnsubscribeService $unsubscribeService,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    #[Route('/api/v1/unsubscribe', methods: ['POST'])]
    public function unsubscribe(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $token = $data['token'] ?? '';
        $sig = $data['sig'] ?? '';

        if (!$token || !$sig) {
            return $this->json(['error' => 'Missing token or signature.'], 400);
        }

        $payload = $this->unsubscribeService->verify($token, $sig);

        if (!$payload) {
            return $this->json(['error' => 'Invalid or expired unsubscribe link.'], 400);
        }

        $email = $payload['email'];
        $category = $payload['category'];
        $userId = $payload['userId'];

        if ($category === 'document') {
            $this->handleDocumentUnsubscribe($email);
        } elseif ($userId) {
            $this->handleNotificationUnsubscribe($userId, $category);
        } else {
            return $this->json(['error' => 'Invalid unsubscribe request.'], 400);
        }

        return $this->json([
            'status' => 'unsubscribed',
            'email' => $email,
            'category' => $category,
        ]);
    }

    private function handleNotificationUnsubscribe(string $userId, string $eventType): void
    {
        $user = $this->entityManager->getRepository(User::class)->find($userId);
        if (!$user) {
            return;
        }

        $preference = $this->entityManager->getRepository(NotificationPreference::class)->findOneBy([
            'user' => $user,
            'eventType' => $eventType,
        ]);

        if ($preference) {
            $preference->setEmailEnabled(false);
        } else {
            $preference = new NotificationPreference();
            $preference->setUser($user);
            $preference->setEventType($eventType);
            $preference->setEmailEnabled(false);
            $this->entityManager->persist($preference);
        }

        $this->entityManager->flush();
    }

    private function handleDocumentUnsubscribe(string $email): void
    {
        $existing = $this->entityManager->getRepository(EmailUnsubscribe::class)->findOneBy([
            'email' => $email,
            'company' => null,
        ]);

        if ($existing) {
            return;
        }

        $unsub = new EmailUnsubscribe();
        $unsub->setEmail($email);
        $unsub->setCategory('document');
        $this->entityManager->persist($unsub);
        $this->entityManager->flush();
    }
}
