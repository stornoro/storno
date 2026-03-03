<?php

namespace App\MessageHandler;

use App\Message\SendMfaEmailOtpMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Email;

#[AsMessageHandler]
class SendMfaEmailOtpHandler
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $mailFrom,
        private readonly ?MailerInterface $mailer = null,
    ) {}

    public function __invoke(SendMfaEmailOtpMessage $message): void
    {
        if (!$this->mailer) {
            $this->logger->warning('Mailer not configured, skipping MFA email OTP.');
            return;
        }

        try {
            $email = (new Email())
                ->from($this->mailFrom)
                ->to($message->email)
                ->subject('Cod de verificare — Storno.ro')
                ->text(sprintf(
                    "Buna,\n\n"
                    . "Codul tau de verificare este: %s\n\n"
                    . "Codul este valid 5 minute.\n\n"
                    . "Daca nu ai solicitat acest cod, ignora acest email.\n\n"
                    . "Echipa Storno.ro",
                    $message->code,
                ));

            $email->getHeaders()->addTextHeader('X-Storno-Email-Category', 'mfa_email_otp');
            $this->mailer->send($email);

            $this->logger->info('MFA email OTP sent.', ['email' => $message->email]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send MFA email OTP.', [
                'email' => $message->email,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
