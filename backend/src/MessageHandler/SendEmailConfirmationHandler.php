<?php

namespace App\MessageHandler;

use App\Entity\EmailConfirmation;
use App\Entity\User;
use App\Message\SendEmailConfirmationMessage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsMessageHandler]
class SendEmailConfirmationHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly TranslatorInterface $translator,
        private readonly string $mailFrom,
        private readonly string $frontendUrl,
        private readonly ?MailerInterface $mailer = null,
    ) {}

    public function __invoke(SendEmailConfirmationMessage $message): void
    {
        $user = $this->entityManager->getRepository(User::class)->find($message->userId);
        if (!$user) {
            $this->logger->warning('User not found for email confirmation.', ['userId' => $message->userId]);
            return;
        }

        if ($user->isEmailVerified()) {
            return;
        }

        // Remove any existing confirmation tokens for this user
        $this->entityManager->createQueryBuilder()
            ->delete(EmailConfirmation::class, 'ec')
            ->where('ec.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();

        $confirmation = new EmailConfirmation();
        $confirmation->setUser($user);

        $this->entityManager->persist($confirmation);
        $this->entityManager->flush();

        if (!$this->mailer) {
            $this->logger->warning('Mailer not configured, skipping confirmation email.');
            return;
        }

        $locale = $user->getLocale();
        $confirmUrl = sprintf('%s/confirm-email?token=%s', rtrim($this->frontendUrl, '/'), $confirmation->getToken());
        $firstName = $user->getFirstName() ? ' ' . $user->getFirstName() : '';

        try {
            $email = (new Email())
                ->from($this->mailFrom)
                ->to($user->getEmail())
                ->subject($this->translator->trans('confirmation.subject', [], 'emails', $locale))
                ->text($this->translator->trans('confirmation.body', [
                    '%firstName%' => $firstName,
                    '%confirmUrl%' => $confirmUrl,
                ], 'emails', $locale));

            $email->getHeaders()->addTextHeader('X-Storno-Email-Category', 'email_confirmation');
            $this->mailer->send($email);

            $this->logger->info('Email confirmation sent.', ['email' => $user->getEmail()]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send email confirmation.', [
                'email' => $user->getEmail(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
