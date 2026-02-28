<?php

namespace App\MessageHandler;

use App\Entity\User;
use App\Message\SendWelcomeEmailMessage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Email;

#[AsMessageHandler]
class SendWelcomeEmailHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly string $mailFrom,
        private readonly string $frontendUrl,
        private readonly ?MailerInterface $mailer = null,
    ) {}

    public function __invoke(SendWelcomeEmailMessage $message): void
    {
        $user = $this->entityManager->getRepository(User::class)->find($message->userId);
        if (!$user) {
            $this->logger->warning('User not found for welcome email.', ['userId' => $message->userId]);
            return;
        }

        if (!$this->mailer) {
            $this->logger->warning('Mailer not configured, skipping welcome email.', ['userId' => $message->userId]);
            return;
        }

        $baseUrl = rtrim($this->frontendUrl, '/');
        $anafUrl = sprintf('%s/efactura', $baseUrl);
        $invoiceUrl = sprintf('%s/invoices/new', $baseUrl);
        $firstName = $user->getFirstName() ? ' ' . $user->getFirstName() : '';

        try {
            $email = (new Email())
                ->from($this->mailFrom)
                ->to($user->getEmail())
                ->subject('Bun venit pe Storno.ro!')
                ->text(sprintf(
                    "Buna%s,\n\n"
                    . "Bun venit pe Storno.ro! Contul tau a fost confirmat si esti gata sa incepi.\n\n"
                    . "Iata cum sa incepi:\n\n"
                    . "1. Conecteaza-te la ANAF pentru sincronizarea automata a facturilor e-Factura:\n"
                    . "   %s\n\n"
                    . "2. Creeaza prima ta factura:\n"
                    . "   %s\n\n"
                    . "Perioada ta de proba de 14 zile iti ofera acces la toate functiile planului Starter:\n"
                    . "- Sincronizare automata e-Factura (la 12 ore)\n"
                    . "- Generare PDF facturi\n"
                    . "- Trimitere facturi pe email\n"
                    . "- Rapoarte si statistici\n"
                    . "- Aplicatie mobila\n"
                    . "- Pana la 3 companii si 3 utilizatori\n\n"
                    . "Daca ai nevoie de ajutor, raspunde la acest email sau scrie-ne la contact@storno.ro.\n\n"
                    . "Echipa Storno.ro",
                    $firstName,
                    $anafUrl,
                    $invoiceUrl,
                ));

            $email->getHeaders()->addTextHeader('X-Storno-Email-Category', 'welcome');
            $this->mailer->send($email);

            $this->logger->info('Welcome email sent.', ['userId' => $message->userId, 'email' => $user->getEmail()]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send welcome email.', [
                'userId' => $message->userId,
                'email' => $user->getEmail(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
