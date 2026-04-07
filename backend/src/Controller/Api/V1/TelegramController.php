<?php
namespace App\Controller\Api\V1;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/api/v1/telegram')]
class TelegramController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ?string $telegramBotUsername = null,
    ) {}

    /**
     * Generate a one-time link token and return a Telegram deep link.
     * The user opens this link in Telegram, clicks Start, and the bot
     * sends the token back to our webhook to complete the linking.
     */
    #[Route('/link', methods: ['POST'])]
    public function generateLink(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$this->telegramBotUsername) {
            return $this->json([
                'error' => 'Telegram integration is not configured.',
                'messageKey' => 'error.telegram.not_configured',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        // Generate a unique token and store it temporarily on the user
        $linkToken = bin2hex(random_bytes(16));
        $user->setTelegramLinkToken($linkToken);
        $user->setTelegramLinkTokenExpiresAt(new \DateTimeImmutable('+15 minutes'));
        $this->entityManager->flush();

        $deepLink = "https://t.me/{$this->telegramBotUsername}?start={$linkToken}";

        return $this->json([
            'url' => $deepLink,
            'expiresIn' => 900, // 15 minutes
        ]);
    }

    /**
     * Unlink Telegram from the user's account.
     */
    #[Route('/unlink', methods: ['POST'])]
    public function unlink(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $user->setTelegramChatId(null);
        $this->entityManager->flush();

        return $this->json(['status' => 'ok']);
    }

    /**
     * Telegram Bot webhook — receives updates from Telegram.
     * This is a PUBLIC endpoint (no auth) called by Telegram servers.
     */
    #[Route('/webhook', methods: ['POST'])]
    public function webhook(Request $request): JsonResponse
    {
        $update = json_decode($request->getContent(), true);

        if (!$update || !isset($update['message'])) {
            return $this->json(['ok' => true]);
        }

        $message = $update['message'];
        $chatId = (string) ($message['chat']['id'] ?? '');
        $text = $message['text'] ?? '';

        // Handle /start <token> command
        if (str_starts_with($text, '/start ')) {
            $token = trim(substr($text, 7));

            if (empty($token) || empty($chatId)) {
                return $this->json(['ok' => true]);
            }

            // Find user with this link token
            $user = $this->entityManager->getRepository(User::class)->findOneBy([
                'telegramLinkToken' => $token,
            ]);

            if (!$user) {
                // Token not found or expired — send error message to Telegram
                $this->sendTelegramMessage($chatId, '❌ Link token is invalid or expired. Please try again from Storno settings.');
                return $this->json(['ok' => true]);
            }

            // Check expiry
            $expiresAt = $user->getTelegramLinkTokenExpiresAt();
            if ($expiresAt && $expiresAt < new \DateTimeImmutable()) {
                $user->setTelegramLinkToken(null);
                $user->setTelegramLinkTokenExpiresAt(null);
                $this->entityManager->flush();
                $this->sendTelegramMessage($chatId, '❌ Link token has expired. Please generate a new one from Storno settings.');
                return $this->json(['ok' => true]);
            }

            // Link successful!
            $user->setTelegramChatId($chatId);
            $user->setTelegramLinkToken(null);
            $user->setTelegramLinkTokenExpiresAt(null);
            $this->entityManager->flush();

            $firstName = $user->getFirstName() ?? 'there';
            $this->sendTelegramMessage($chatId, "✅ Hi {$firstName}! Your Telegram is now linked to Storno. You'll receive notifications here.");

            return $this->json(['ok' => true]);
        }

        return $this->json(['ok' => true]);
    }

    /**
     * Check current Telegram link status.
     */
    #[Route('/status', methods: ['GET'])]
    public function status(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->json([
            'linked' => $user->getTelegramChatId() !== null,
            'configured' => !empty($this->telegramBotUsername),
        ]);
    }

    private function sendTelegramMessage(string $chatId, string $text): void
    {
        $botToken = $_ENV['TELEGRAM_BOT_TOKEN'] ?? '';
        if (!$botToken) return;

        $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
}
