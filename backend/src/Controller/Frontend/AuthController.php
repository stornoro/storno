<?php

namespace App\Controller\Frontend;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Attribute\Route;

#[Route(path: '/api')]
class AuthController extends AbstractController
{
    #[Route(path: '/auth', name: 'jwt_auth')]
    public function login()
    {
        // This route is handled by security controller
    }
}
