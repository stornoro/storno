<?php

namespace App\Controller\Frontend\Account;


use App\Manager\StatsManager;
use App\Manager\UserManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(path: '/api/account')]
#[IsGranted('ROLE_USER', statusCode: 403)]
class AccountController extends AbstractController
{
    #[Route(
        '/me',
        name: 'frontend_api_user',
    )]
    public function index(StatsManager $statsManager): JsonResponse
    {
        $user = $this->getUser();

        return $this->json([
            'user' => $user,
            'stats' => $statsManager->getStatistics()
        ]);
    }
    #[Route(
        '/developerMode',
        name: 'frontend_api_user_developerMode',
        methods: ['POST']
    )]
    public function toggleDeveloperMode(UserManager $userManager): JsonResponse
    {

        $userManager->toggleDeveloperMode();

        return $this->json([
            'status' => 'ok'
        ]);
    }
}
