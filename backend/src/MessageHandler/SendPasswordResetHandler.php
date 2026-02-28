<?php

namespace App\MessageHandler;

use App\Message\SendPasswordResetMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Email;

#[AsMessageHandler]
class SendPasswordResetHandler
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $mailFrom,
        private readonly string $frontendUrl,
        private readonly ?MailerInterface $mailer = null,
    ) {}

    public function __invoke(SendPasswordResetMessage $message): void
    {
        if (!$this->mailer) {
            $this->logger->warning('Mailer not configured, skipping password reset email.');
            return;
        }

        $resetUrl = sprintf('%s/reset-password?token=%s', rtrim($this->frontendUrl, '/'), $message->token);

        try {
            $email = (new Email())
                ->from($this->mailFrom)
                ->to($message->email)
                ->subject('Resetare parola — Storno.ro')
                ->text(sprintf(
                    "Buna,\n\n"
                    . "Am primit o cerere de resetare a parolei pentru contul tau Storno.ro.\n\n"
                    . "Reseteaza parola accesand linkul de mai jos:\n%s\n\n"
                    . "Linkul este valid 2 ore.\n\n"
                    . "Daca nu ai solicitat resetarea parolei, ignora acest email.\n\n"
                    . "Echipa Storno.ro",
                    $resetUrl,
                ));

            $email->getHeaders()->addTextHeader('X-Storno-Email-Category', 'password_reset');
            $this->mailer->send($email);

            $this->logger->info('Password reset email sent.', ['email' => $message->email]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send password reset email.', [
                'email' => $message->email,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
