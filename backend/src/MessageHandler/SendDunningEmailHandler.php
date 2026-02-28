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

#[AsMessageHandler]
class SendDunningEmailHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
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

        $billingUrl = sprintf('%s/settings/billing', rtrim($this->frontendUrl, '/'));
        $firstName = $owner->getFirstName() ? ' ' . $owner->getFirstName() : '';

        [$subject, $body] = match ($message->attempt) {
            1 => [
                'Plata a esuat — actualizeaza metoda de plata',
                sprintf(
                    "Buna%s,\n\n"
                    . "Plata abonamentului Storno.ro pentru organizatia \"%s\" a esuat.\n\n"
                    . "Te rugam sa actualizezi metoda de plata pentru a evita intreruperea serviciului:\n%s\n\n"
                    . "Daca ai intrebari, ne poti contacta la contact@storno.ro.\n\n"
                    . "Echipa Storno.ro",
                    $firstName,
                    $org->getName(),
                    $billingUrl,
                ),
            ],
            2 => [
                'Abonamentul tau este in pericol — actualizeaza metoda de plata',
                sprintf(
                    "Buna%s,\n\n"
                    . "Inca nu am reusit sa procesam plata abonamentului Storno.ro pentru organizatia \"%s\".\n\n"
                    . "Actualizeaza metoda de plata cat mai curand pentru a pastra accesul la toate functiile:\n%s\n\n"
                    . "Daca nu actualizezi metoda de plata in urmatoarele zile, "
                    . "accesul la contul tau va fi suspendat.\n\n"
                    . "Echipa Storno.ro",
                    $firstName,
                    $org->getName(),
                    $billingUrl,
                ),
            ],
            default => [
                'Ultima sansa — abonamentul tau va fi anulat',
                sprintf(
                    "Buna%s,\n\n"
                    . "Aceasta este ultima notificare privind plata esecuata pentru abonamentul Storno.ro "
                    . "al organizatiei \"%s\".\n\n"
                    . "Daca nu actualizezi metoda de plata astazi, abonamentul tau va fi anulat "
                    . "si vei pierde accesul la toate functiile premium.\n\n"
                    . "Actualizeaza acum:\n%s\n\n"
                    . "Echipa Storno.ro",
                    $firstName,
                    $org->getName(),
                    $billingUrl,
                ),
            ],
        };

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
