<?php

namespace App\MessageHandler;

use App\Entity\OrganizationInvitation;
use App\Message\SendInvitationEmailMessage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsMessageHandler]
class SendInvitationEmailHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly TranslatorInterface $translator,
        private readonly string $mailFrom,
        private readonly string $frontendUrl,
        private readonly ?MailerInterface $mailer = null,
    ) {}

    public function __invoke(SendInvitationEmailMessage $message): void
    {
        $invitation = $this->entityManager->getRepository(OrganizationInvitation::class)->find($message->invitationId);

        if (!$invitation) {
            $this->logger->warning('Invitation not found for email sending.', ['id' => $message->invitationId]);
            return;
        }

        if (!$invitation->isPending()) {
            $this->logger->info('Invitation is no longer pending, skipping email.', ['id' => $message->invitationId]);
            return;
        }

        if (!$this->mailer) {
            $this->logger->warning('Mailer not configured, skipping invitation email.', ['id' => $message->invitationId]);
            return;
        }

        $locale = $invitation->getInvitedBy()->getLocale();
        $orgName = $invitation->getOrganization()->getName();
        $inviterName = sprintf('%s %s', $invitation->getInvitedBy()->getFirstName(), $invitation->getInvitedBy()->getLastName());
        $acceptUrl = sprintf('%s/invite/%s', rtrim($this->frontendUrl, '/'), $invitation->getToken());

        try {
            $email = (new Email())
                ->from($this->mailFrom)
                ->to($invitation->getEmail())
                ->subject($this->translator->trans('invitation.subject', [
                    '%orgName%' => $orgName,
                ], 'emails', $locale))
                ->text($this->translator->trans('invitation.body', [
                    '%inviterName%' => $inviterName,
                    '%orgName%' => $orgName,
                    '%role%' => $invitation->getRole()->label(),
                    '%acceptUrl%' => $acceptUrl,
                    '%expiresAt%' => $invitation->getExpiresAt()->format('d.m.Y H:i'),
                ], 'emails', $locale));

            $email->getHeaders()->addTextHeader('X-Storno-Email-Category', 'invitation');
            $this->mailer->send($email);

            $this->logger->info('Invitation email sent.', [
                'id' => $message->invitationId,
                'email' => $invitation->getEmail(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send invitation email.', [
                'id' => $message->invitationId,
                'email' => $invitation->getEmail(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
