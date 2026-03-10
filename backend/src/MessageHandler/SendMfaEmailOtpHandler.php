<?php

namespace App\MessageHandler;

use App\Message\SendMfaEmailOtpMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsMessageHandler]
class SendMfaEmailOtpHandler
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly TranslatorInterface $translator,
        private readonly string $mailFrom,
        private readonly ?MailerInterface $mailer = null,
    ) {}

    public function __invoke(SendMfaEmailOtpMessage $message): void
    {
        if (!$this->mailer) {
            $this->logger->warning('Mailer not configured, skipping MFA email OTP.');
            return;
        }

        $locale = $message->locale;

        try {
            $email = (new Email())
                ->from($this->mailFrom)
                ->to($message->email)
                ->subject($this->translator->trans('mfa_otp.subject', [], 'emails', $locale))
                ->text($this->translator->trans('mfa_otp.body', [
                    '%code%' => $message->code,
                ], 'emails', $locale));

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
