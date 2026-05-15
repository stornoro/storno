<?php

namespace App\MessageHandler;

use App\Entity\EmailLog;
use App\Entity\User;
use App\Enum\EmailStatus;
use App\Message\SendReEngagementEmailMessage;
use App\Service\LifecycleEmailGate;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsMessageHandler]
class SendReEngagementEmailHandler
{
    private const CATEGORY = 're_engagement';
    private const FROM_NAME = 'Florin de la Storno';
    private const REPLY_TO = 'contact@storno.ro';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly TranslatorInterface $translator,
        private readonly LifecycleEmailGate $gate,
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

        $logEntry = $this->initLog($user->getEmail(), null, $user);

        if (!$this->gate->canSend($user->getEmail(), self::CATEGORY, $user)) {
            $this->logger->info('Re-engagement email suppressed by gate.', ['userId' => $message->userId]);
            $this->finalizeLog($logEntry, EmailStatus::SENT, 'skipped_gate');
            return;
        }

        if (!$this->mailer) {
            $this->logger->warning('Mailer not configured, skipping re-engagement email.', ['userId' => $message->userId]);
            $this->finalizeLog($logEntry, EmailStatus::FAILED, null, 'Mailer not configured');
            return;
        }

        $locale = $user->getLocale();
        $baseUrl = rtrim($this->frontendUrl, '/');
        $firstName = $user->getFirstName() ? ' ' . $user->getFirstName() : '';

        $subject = $this->translator->trans('lifecycle.re_engagement.subject', [], 'emails', $locale);
        $body = $this->translator->trans('lifecycle.re_engagement.body', [
            '%firstName%' => $firstName,
            '%baseUrl%' => $baseUrl,
        ], 'emails', $locale);

        $logEntry->setSubject($subject);

        try {
            $email = (new Email())
                ->from(new Address($this->mailFrom, self::FROM_NAME))
                ->replyTo(self::REPLY_TO)
                ->to($user->getEmail())
                ->subject($subject)
                ->text($body);

            $email->getHeaders()->addTextHeader('X-Storno-Email-Category', self::CATEGORY);
            $email->getHeaders()->addTextHeader('X-Storno-Email-Tracked', 'true');
            $this->mailer->send($email);

            $this->finalizeLog($logEntry, EmailStatus::SENT);
            $this->logger->info('Re-engagement email sent.', [
                'userId' => $message->userId,
                'email' => $user->getEmail(),
            ]);
        } catch (\Throwable $e) {
            $this->finalizeLog($logEntry, EmailStatus::FAILED, null, $e->getMessage());
            $this->logger->error('Failed to send re-engagement email.', [
                'userId' => $message->userId,
                'email' => $user->getEmail(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function initLog(string $toEmail, ?string $variant, ?User $user): EmailLog
    {
        $log = new EmailLog();
        $log->setToEmail($toEmail);
        $log->setCategory(self::CATEGORY);
        $log->setSubject('');
        $log->setStatus(EmailStatus::SENT);
        $log->setSentBy($user);
        $this->entityManager->persist($log);

        return $log;
    }

    private function finalizeLog(EmailLog $log, EmailStatus $status, ?string $templateUsed = null, ?string $error = null): void
    {
        $log->setStatus($status);
        if ($templateUsed !== null) {
            $log->setTemplateUsed($templateUsed);
        }
        if ($error !== null) {
            $log->setErrorMessage($error);
        }
        $this->entityManager->flush();
    }
}
