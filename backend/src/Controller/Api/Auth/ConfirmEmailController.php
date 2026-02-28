<?php

namespace App\Controller\Api\Auth;

use App\Entity\EmailConfirmation;
use App\Entity\User;
use App\Message\SendEmailConfirmationMessage;
use App\Message\SendWelcomeEmailMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

class ConfirmEmailController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $messageBus,
    ) {}

    #[Route('/api/auth/confirm-email', name: 'api_auth_confirm_email', methods: ['POST'])]
    public function confirm(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $token = $data['token'] ?? null;

        if (!$token) {
            return $this->json(['error' => 'Token is required.'], Response::HTTP_BAD_REQUEST);
        }

        $confirmation = $this->entityManager->getRepository(EmailConfirmation::class)->findOneBy(['token' => $token]);

        if (!$confirmation) {
            return $this->json(['error' => 'Invalid confirmation token.'], Response::HTTP_BAD_REQUEST);
        }

        if ($confirmation->isExpired()) {
            $this->entityManager->remove($confirmation);
            $this->entityManager->flush();
            return $this->json(['error' => 'Confirmation token has expired.'], Response::HTTP_BAD_REQUEST);
        }

        $user = $confirmation->getUser();
        $user->setEmailVerified(true);

        $this->entityManager->remove($confirmation);
        $this->entityManager->flush();

        $this->messageBus->dispatch(new SendWelcomeEmailMessage((string) $user->getId()));

        return $this->json(['message' => 'Email confirmed successfully.']);
    }

    #[Route('/api/auth/resend-confirmation', name: 'api_auth_resend_confirmation', methods: ['POST'])]
    public function resend(Request $request, RateLimiterFactory $registerLimiter): JsonResponse
    {
        $limiter = $registerLimiter->create('resend_' . $request->getClientIp());
        if (!$limiter->consume()->isAccepted()) {
            return $this->json(['error' => 'Too many requests. Please try again later.'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $data = json_decode($request->getContent(), true);
        $email = $data['email'] ?? null;

        if (!$email) {
            return $this->json(['error' => 'Email is required.'], Response::HTTP_BAD_REQUEST);
        }

        // Always return success to prevent email enumeration
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);

        if ($user && !$user->isEmailVerified()) {
            $this->messageBus->dispatch(new SendEmailConfirmationMessage((string) $user->getId()));
        }

        return $this->json(['message' => 'If this email exists and is not confirmed, a confirmation email has been sent.']);
    }
}
