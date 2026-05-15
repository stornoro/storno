<?php

namespace App\MessageHandler;

use App\Entity\EmailLog;
use App\Entity\Organization;
use App\Entity\OrganizationMembership;
use App\Entity\User;
use App\Enum\EmailStatus;
use App\Enum\OrganizationRole;
use App\Message\SendFirstCompanyCreatedEmailMessage;
use App\Service\LifecycleEmailGate;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsMessageHandler]
class SendFirstCompanyCreatedEmailHandler
{
    private const CATEGORY = 'first_company_created';
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

    public function __invoke(SendFirstCompanyCreatedEmailMessage $message): void
    {
        $org = $this->entityManager->getRepository(Organization::class)->find($message->organizationId);
        if (!$org) {
            $this->logger->warning('Organization not found for first-company-created email.', [
                'organizationId' => $message->organizationId,
            ]);
            return;
        }

        $owner = $this->findOwner($org);
        if (!$owner) {
            $this->logger->warning('No active owner found for first-company-created email.', [
                'organizationId' => $message->organizationId,
            ]);
            return;
        }

        $logEntry = $this->initLog($owner->getEmail(), $owner);

        if (!$this->gate->canSend($owner->getEmail(), self::CATEGORY, $owner)) {
            $this->logger->info('First-company-created email suppressed by gate.', [
                'organizationId' => $message->organizationId,
            ]);
            $this->finalizeLog($logEntry, EmailStatus::SENT, 'skipped_gate');
            return;
        }

        if (!$this->mailer) {
            $this->logger->warning('Mailer not configured, skipping first-company-created email.', [
                'organizationId' => $message->organizationId,
            ]);
            $this->finalizeLog($logEntry, EmailStatus::FAILED, null, 'Mailer not configured');
            return;
        }

        $locale = $owner->getLocale();
        $baseUrl = rtrim($this->frontendUrl, '/');
        $firstName = $owner->getFirstName() ? ' ' . $owner->getFirstName() : '';

        $subject = $this->translator->trans('lifecycle.first_company_created.subject', [], 'emails', $locale);
        $body = $this->translator->trans('lifecycle.first_company_created.body', [
            '%firstName%' => $firstName,
            '%baseUrl%' => $baseUrl,
            '%orgName%' => $org->getName(),
        ], 'emails', $locale);

        $logEntry->setSubject($subject);

        try {
            $email = (new Email())
                ->from(new Address($this->mailFrom, self::FROM_NAME))
                ->replyTo(self::REPLY_TO)
                ->to($owner->getEmail())
                ->subject($subject)
                ->text($body);

            $email->getHeaders()->addTextHeader('X-Storno-Email-Category', self::CATEGORY);
            $email->getHeaders()->addTextHeader('X-Storno-Email-Tracked', 'true');
            $this->mailer->send($email);

            $this->finalizeLog($logEntry, EmailStatus::SENT);
            $this->logger->info('First-company-created email sent.', [
                'organizationId' => $message->organizationId,
                'email' => $owner->getEmail(),
            ]);
        } catch (\Throwable $e) {
            $this->finalizeLog($logEntry, EmailStatus::FAILED, null, $e->getMessage());
            $this->logger->error('Failed to send first-company-created email.', [
                'organizationId' => $message->organizationId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function findOwner(Organization $org): ?User
    {
        $membership = $this->entityManager->getRepository(OrganizationMembership::class)->findOneBy([
            'organization' => $org,
            'role' => OrganizationRole::OWNER,
            'isActive' => true,
        ]);

        return $membership?->getUser();
    }

    private function initLog(string $toEmail, ?User $user): EmailLog
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
