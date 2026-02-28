<?php

namespace App\MessageHandler;

use App\Entity\User;
use App\Message\SendReEngagementEmailMessage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Email;

#[AsMessageHandler]
class SendReEngagementEmailHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly string $mailFrom,
        private readonly string $frontendUrl,
        private readonly ?MailerInterface $mailer = null,
    ) {}

    public function __invoke(SendReEngagementEmailMessage $message): void
    {
        $user = $this->entityManager->getRepository(User::class)->find($message->userId);
        if (!$user) {
            $this->logger->warning('User not found for re-engagement email.', ['userId' => $message->userId]);
            return;
        }

        if (!$this->mailer) {
            $this->logger->warning('Mailer not configured, skipping re-engagement email.', ['userId' => $message->userId]);
            return;
        }

        $baseUrl = rtrim($this->frontendUrl, '/');
        $firstName = $user->getFirstName() ? ' ' . $user->getFirstName() : '';

        try {
            $email = (new Email())
                ->from($this->mailFrom)
                ->to($user->getEmail())
                ->subject('Ne este dor de tine — ce e nou pe Storno.ro')
                ->text(sprintf(
                    "Buna%s,\n\n"
                    . "Nu te-am mai vazut pe Storno.ro de ceva vreme si ne-am gandit sa te informam "
                    . "despre ce am adaugat recent:\n\n"
                    . "- Sincronizare imbunatatita cu e-Factura ANAF\n"
                    . "- Export avansat CSV si PDF\n"
                    . "- Statistici si rapoarte detaliate\n"
                    . "- Notificari pentru facturi scadente\n\n"
                    . "Revino si verifica toate noutatile:\n%s\n\n"
                    . "Daca ai intrebari sau sugestii, scrie-ne la contact@storno.ro.\n\n"
                    . "Echipa Storno.ro",
                    $firstName,
                    $baseUrl,
                ));

            $email->getHeaders()->addTextHeader('X-Storno-Email-Category', 're_engagement');
            $this->mailer->send($email);

            $this->logger->info('Re-engagement email sent.', [
                'userId' => $message->userId,
                'email' => $user->getEmail(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send re-engagement email.', [
                'userId' => $message->userId,
                'email' => $user->getEmail(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
