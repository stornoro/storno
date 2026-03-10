<?php

namespace App\MessageHandler;

use App\Entity\Organization;
use App\Entity\OrganizationMembership;
use App\Enum\OrganizationRole;
use App\Message\SendTrialExpirationMessage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsMessageHandler]
class SendTrialExpirationHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly TranslatorInterface $translator,
        private readonly string $mailFrom,
        private readonly string $frontendUrl,
        private readonly ?MailerInterface $mailer = null,
    ) {}

    public function __invoke(SendTrialExpirationMessage $message): void
    {
        $org = $this->entityManager->getRepository(Organization::class)->find($message->organizationId);
        if (!$org) {
            $this->logger->warning('Organization not found for trial expiration email.', [
                'organizationId' => $message->organizationId,
            ]);
            return;
        }

        $owner = $this->findOwner($org);
        if (!$owner) {
            $this->logger->warning('No active owner found for trial expiration email.', [
                'organizationId' => $message->organizationId,
            ]);
            return;
        }

        if (!$this->mailer) {
            $this->logger->warning('Mailer not configured, skipping trial expiration email.', [
                'organizationId' => $message->organizationId,
            ]);
            return;
        }

        $locale = $owner->getLocale();
        $billingUrl = sprintf('%s/settings/billing', rtrim($this->frontendUrl, '/'));
        $firstName = $owner->getFirstName() ? ' ' . $owner->getFirstName() : '';
        $daysLeft = $message->daysLeft;

        $urgencyKey = match (true) {
            $daysLeft <= 1 => 'urgency_tomorrow',
            $daysLeft <= 3 => 'urgency_3days',
            default => 'urgency_7days',
        };
        $urgency = $this->translator->trans('trial_expiration.' . $urgencyKey, [], 'emails', $locale);

        $subject = $this->translator->trans('trial_expiration.subject', [
            '%urgency%' => $urgency,
        ], 'emails', $locale);

        $body = $this->translator->trans('trial_expiration.body', [
            '%firstName%' => $firstName,
            '%orgName%' => $org->getName(),
            '%urgency%' => $urgency,
            '%trialEndsAt%' => $org->getTrialEndsAt()?->format('d.m.Y') ?? '',
            '%billingUrl%' => $billingUrl,
        ], 'emails', $locale);

        try {
            $email = (new Email())
                ->from($this->mailFrom)
                ->to($owner->getEmail())
                ->subject($subject)
                ->text($body);

            $email->getHeaders()->addTextHeader('X-Storno-Email-Category', 'trial_expiration');
            $this->mailer->send($email);

            $this->logger->info('Trial expiration email sent.', [
                'organizationId' => $message->organizationId,
                'daysLeft' => $daysLeft,
                'email' => $owner->getEmail(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send trial expiration email.', [
                'organizationId' => $message->organizationId,
                'daysLeft' => $daysLeft,
                'email' => $owner->getEmail(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function findOwner(Organization $org): ?\App\Entity\User
    {
        $membership = $this->entityManager->getRepository(OrganizationMembership::class)->findOneBy([
            'organization' => $org,
            'role' => OrganizationRole::OWNER,
            'isActive' => true,
        ]);

        return $membership?->getUser();
    }
}
