<?php

namespace App\MessageHandler;

use App\Entity\User;
use App\Message\SendWelcomeEmailMessage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsMessageHandler]
class SendWelcomeEmailHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly TranslatorInterface $translator,
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

        $locale = $user->getLocale();
        $baseUrl = rtrim($this->frontendUrl, '/');
        $anafUrl = sprintf('%s/efactura', $baseUrl);
        $invoiceUrl = sprintf('%s/invoices/new', $baseUrl);
        $firstName = $user->getFirstName() ? ' ' . $user->getFirstName() : '';

        try {
            $email = (new Email())
                ->from($this->mailFrom)
                ->to($user->getEmail())
                ->subject($this->translator->trans('welcome.subject', [], 'emails', $locale))
                ->text($this->translator->trans('welcome.body', [
                    '%firstName%' => $firstName,
                    '%anafUrl%' => $anafUrl,
                    '%invoiceUrl%' => $invoiceUrl,
                ], 'emails', $locale));

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
