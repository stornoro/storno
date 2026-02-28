<?php

namespace App\Controller\Api\Auth;

use App\Entity\ResetPassword;
use App\Entity\User;
use App\Message\SendPasswordResetMessage;
use App\Service\TurnstileVerifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

class PasswordResetController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly TurnstileVerifier $turnstileVerifier,
        private readonly MessageBusInterface $messageBus,
    ) {}

    #[Route('/api/auth/forgot-password', name: 'api_auth_forgot_password', methods: ['POST'])]
    public function forgotPassword(Request $request, RateLimiterFactory $passwordResetLimiter): JsonResponse
    {
        $limiter = $passwordResetLimiter->create($request->getClientIp());
        if (!$limiter->consume()->isAccepted()) {
            return $this->json(['error' => 'Too many requests.'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $data = json_decode($request->getContent(), true);

        // Verify Turnstile
        $turnstileToken = $data['turnstileToken'] ?? '';
        if (!$this->turnstileVerifier->verify($turnstileToken, $request->getClientIp())) {
            return $this->json(['error' => 'Captcha verification failed.'], Response::HTTP_FORBIDDEN);
        }

        $email = $data['email'] ?? null;

        if (!$email) {
            return $this->json(['error' => 'Email is required.'], Response::HTTP_BAD_REQUEST);
        }

        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);

        // Always return success to prevent email enumeration
        if (!$user) {
            return $this->json(['message' => 'If an account exists with this email, a reset link has been sent.']);
        }

        $token = bin2hex(random_bytes(32));
        $resetPassword = new ResetPassword();
        $resetPassword->setUser($user);
        $resetPassword->setToken($token);
        $resetPassword->setRequestedAt(new \DateTimeImmutable());
        $resetPassword->setExpiresAt(new \DateTimeImmutable('+2 hours'));

        $this->entityManager->persist($resetPassword);
        $this->entityManager->flush();

        $this->messageBus->dispatch(new SendPasswordResetMessage($user->getEmail(), $token));

        return $this->json(['message' => 'If an account exists with this email, a reset link has been sent.']);
    }

    #[Route('/api/auth/reset-password', name: 'api_auth_reset_password', methods: ['POST'])]
    public function resetPassword(Request $request, RateLimiterFactory $passwordResetLimiter): JsonResponse
    {
        $limiter = $passwordResetLimiter->create('reset_' . $request->getClientIp());
        if (!$limiter->consume()->isAccepted()) {
            return $this->json(['error' => 'Too many requests.'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $data = json_decode($request->getContent(), true);
        $token = $data['token'] ?? null;
        $newPassword = $data['password'] ?? null;

        if (!$token || !$newPassword) {
            return $this->json(['error' => 'Token and new password are required.'], Response::HTTP_BAD_REQUEST);
        }

        if (strlen($newPassword) < 8) {
            return $this->json(['error' => 'Password must be at least 8 characters.'], Response::HTTP_BAD_REQUEST);
        }

        $resetPassword = $this->entityManager->getRepository(ResetPassword::class)->findOneBy(['token' => $token]);

        if (!$resetPassword || $resetPassword->getExpiresAt() < new \DateTimeImmutable()) {
            return $this->json(['error' => 'Invalid or expired reset token.'], Response::HTTP_BAD_REQUEST);
        }

        $user = $resetPassword->getUser();
        $user->setPassword($this->passwordHasher->hashPassword($user, $newPassword));

        $this->entityManager->remove($resetPassword);
        $this->entityManager->flush();

        return $this->json(['message' => 'Password has been reset successfully.']);
    }
}
