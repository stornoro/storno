<?php

namespace App\Controller\Frontend\Account;

use App\Manager\NotificationManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(path: '/api/account')]
#[IsGranted('ROLE_USER', statusCode: 403)]
class NotificationController extends AbstractController
{
    #[Route('/me/notifications', name: 'frontend_api_user_notifications', methods: ['GET'])]
    public function notifications(Request $request, NotificationManager $notificationManager): JsonResponse
    {
        $offset = $request->query->get('offset', 0);
        $notifications = $notificationManager->latest($offset);

        return $this->json($notifications);
    }

    #[Route('/me/notifications/mark-all-read', name: 'frontend_api_user_notifications_mark_all_read')]
    public function markRead(NotificationManager $notificationManager): JsonResponse
    {
        $user = $this->getUser();
        $notificationManager->markRead();

        return $this->json(['status' => 'ok']);
    }
    #[Route('/me/notifications', name: 'frontend_api_user_notifications_delete', methods: ['DELETE'])]
    public function delete(NotificationManager $notificationManager): JsonResponse
    {
        $user = $this->getUser();
        $notificationManager->markRead();

        return $this->json(['status' => 'ok']);
    }
}
