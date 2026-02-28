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

#[AsMessageHandler]
class SendTrialExpirationHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
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

        $billingUrl = sprintf('%s/settings/billing', rtrim($this->frontendUrl, '/'));
        $firstName = $owner->getFirstName() ? ' ' . $owner->getFirstName() : '';
        $daysLeft = $message->daysLeft;

        $urgency = match (true) {
            $daysLeft <= 1 => 'maine',
            $daysLeft <= 3 => 'in 3 zile',
            default => 'in 7 zile',
        };

        $subject = sprintf(
            'Perioada de proba expira %s — aboneaza-te pentru a pastra accesul',
            $urgency,
        );

        $body = sprintf(
            "Buna%s,\n\n"
            . "Perioada ta de proba Storno.ro pentru organizatia \"%s\" expira %s "
            . "(%s).\n\n"
            . "Dupa expirare, vei pierde accesul la:\n"
            . "- Sincronizare automata e-Factura\n"
            . "- Generare PDF facturi\n"
            . "- Trimitere facturi pe email\n"
            . "- Rapoarte si statistici\n"
            . "- Aplicatie mobila\n"
            . "- Si multe altele din planul Starter\n\n"
            . "Aboneaza-te acum pentru a pastra toate aceste functii:\n%s\n\n"
            . "Echipa Storno.ro",
            $firstName,
            $org->getName(),
            $urgency,
            $org->getTrialEndsAt()?->format('d.m.Y') ?? '',
            $billingUrl,
        );

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
