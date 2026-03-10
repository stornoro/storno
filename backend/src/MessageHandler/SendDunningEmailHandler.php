<?php

namespace App\MessageHandler;

use App\Entity\Organization;
use App\Entity\OrganizationMembership;
use App\Enum\OrganizationRole;
use App\Message\SendDunningEmailMessage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsMessageHandler]
class SendDunningEmailHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly TranslatorInterface $translator,
        private readonly string $mailFrom,
        private readonly string $frontendUrl,
        private readonly ?MailerInterface $mailer = null,
    ) {}

    public function __invoke(SendDunningEmailMessage $message): void
    {
        $org = $this->entityManager->getRepository(Organization::class)->find($message->organizationId);
        if (!$org) {
            $this->logger->warning('Organization not found for dunning email.', [
                'organizationId' => $message->organizationId,
                'attempt' => $message->attempt,
            ]);
            return;
        }

        $owner = $this->findOwner($org);
        if (!$owner) {
            $this->logger->warning('No active owner found for dunning email.', [
                'organizationId' => $message->organizationId,
            ]);
            return;
        }

        if (!$this->mailer) {
            $this->logger->warning('Mailer not configured, skipping dunning email.', [
                'organizationId' => $message->organizationId,
                'attempt' => $message->attempt,
            ]);
            return;
        }

        $locale = $owner->getLocale();
        $billingUrl = sprintf('%s/settings/billing', rtrim($this->frontendUrl, '/'));
        $firstName = $owner->getFirstName() ? ' ' . $owner->getFirstName() : '';
        $params = [
            '%firstName%' => $firstName,
            '%orgName%' => $org->getName(),
            '%billingUrl%' => $billingUrl,
        ];

        $attemptKey = match ($message->attempt) {
            1 => 'attempt1',
            2 => 'attempt2',
            default => 'attempt3',
        };

        $subject = $this->translator->trans('dunning.' . $attemptKey . '_subject', $params, 'emails', $locale);
        $body = $this->translator->trans('dunning.' . $attemptKey . '_body', $params, 'emails', $locale);

        try {
            $email = (new Email())
                ->from($this->mailFrom)
                ->to($owner->getEmail())
                ->subject($subject)
                ->text($body);

            $email->getHeaders()->addTextHeader('X-Storno-Email-Category', 'dunning');
            $this->mailer->send($email);

            $this->logger->info('Dunning email sent.', [
                'organizationId' => $message->organizationId,
                'attempt' => $message->attempt,
                'email' => $owner->getEmail(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send dunning email.', [
                'organizationId' => $message->organizationId,
                'attempt' => $message->attempt,
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
