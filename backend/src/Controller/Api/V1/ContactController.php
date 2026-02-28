<?php

namespace App\Controller\Api\V1;

use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ContactController extends AbstractController
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $turnstileSecretKey,
        private readonly string $mailFrom,
        private readonly string $contactEmail,
    ) {}

    #[Route('/api/v1/contact', methods: ['POST'])]
    public function __invoke(Request $request, RateLimiterFactory $contactLimiter): JsonResponse
    {
        $limiter = $contactLimiter->create($request->getClientIp());
        if (!$limiter->consume()->isAccepted()) {
            return $this->json(['error' => 'Too many requests.'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $data = json_decode($request->getContent(), true);

        $name = trim($data['name'] ?? '');
        $email = trim($data['email'] ?? '');
        $subject = trim($data['subject'] ?? '');
        $message = trim($data['message'] ?? '');
        $turnstileToken = $data['turnstileToken'] ?? '';

        // Validate required fields
        if (!$name || !$email || !$subject || !$message) {
            return $this->json(['error' => 'All fields are required.'], Response::HTTP_BAD_REQUEST);
        }

        if (!filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            return $this->json(['error' => 'Invalid email address.'], Response::HTTP_BAD_REQUEST);
        }

        // Verify Turnstile token
        if (!$turnstileToken || !$this->verifyTurnstile($turnstileToken, $request->getClientIp())) {
            return $this->json(['error' => 'Turnstile verification failed.'], Response::HTTP_FORBIDDEN);
        }

        // Send email
        try {
            $emailMessage = (new Email())
                ->from($this->mailFrom)
                ->to($this->contactEmail)
                ->replyTo($email)
                ->subject("[Contact] {$subject}")
                ->text(
                    "Nume: {$name}\n"
                    . "Email: {$email}\n"
                    . "Subiect: {$subject}\n\n"
                    . $message
                );

            $emailMessage->getHeaders()->addTextHeader('X-Storno-Email-Category', 'contact');
            $this->mailer->send($emailMessage);
        } catch (\Exception $e) {
            $this->logger->error('Contact form email failed', ['error' => $e->getMessage()]);

            return $this->json(['error' => 'Failed to send message.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->json(['status' => 'sent']);
    }

    private function verifyTurnstile(string $token, ?string $remoteIp): bool
    {
        if (!$this->turnstileSecretKey) {
            // No secret key configured — skip verification in dev
            return true;
        }

        try {
            $response = $this->httpClient->request('POST', 'https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                'body' => [
                    'secret' => $this->turnstileSecretKey,
                    'response' => $token,
                    'remoteip' => $remoteIp,
                ],
            ]);

            $result = $response->toArray(false);

            if (!($result['success'] ?? false)) {
                $this->logger->warning('Turnstile verification failed', ['errors' => $result['error-codes'] ?? []]);
            }

            return $result['success'] ?? false;
        } catch (\Exception $e) {
            $this->logger->error('Turnstile API call failed', ['error' => $e->getMessage()]);

            return false;
        }
    }
}
