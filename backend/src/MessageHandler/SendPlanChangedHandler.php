<?php

namespace App\MessageHandler;

use App\Message\SendPlanChangedMessage;
use App\Repository\OrganizationRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

#[AsMessageHandler]
class SendPlanChangedHandler
{
    public function __construct(
        private readonly OrganizationRepository $organizationRepository,
        private readonly Environment $twig,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
        private readonly string $mailFrom,
        private readonly string $frontendUrl,
        private readonly ?MailerInterface $mailer = null,
    ) {}

    public function __invoke(SendPlanChangedMessage $message): void
    {
        if (!$this->mailer) {
            return;
        }

        $org = $this->organizationRepository->find($message->organizationId);
        if (!$org) {
            return;
        }

        foreach ($org->getMemberships() as $membership) {
            $user = $membership->getUser();
            if (!$user) {
                continue;
            }

            $locale = $user->getLocale();
            $isUpgrade = $this->isPlanUpgrade($message->oldPlan, $message->newPlan);

            try {
                $html = $this->twig->render('emails/plan_changed.html.twig', [
                    'firstName' => $user->getFirstName() ?: $user->getEmail(),
                    'oldPlan' => ucfirst($message->oldPlan),
                    'newPlan' => ucfirst($message->newPlan),
                    'isUpgrade' => $isUpgrade,
                    'frontendUrl' => rtrim($this->frontendUrl, '/'),
                    'locale' => $locale,
                ]);

                $email = (new Email())
                    ->from($this->mailFrom)
                    ->to($user->getEmail())
                    ->subject($this->translator->trans(
                        $isUpgrade ? 'plan_changed.subject_upgrade' : 'plan_changed.subject_downgrade',
                        ['%newPlan%' => ucfirst($message->newPlan)],
                        'emails',
                        $locale,
                    ))
                    ->html($html);

                $email->getHeaders()->addTextHeader('X-Storno-Email-Category', 'plan_changed');
                $this->mailer->send($email);
            } catch (\Throwable $e) {
                $this->logger->error('Failed to send plan changed email.', [
                    'email' => $user->getEmail(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function isPlanUpgrade(string $oldPlan, string $newPlan): bool
    {
        $order = ['freemium' => 0, 'starter' => 1, 'professional' => 2, 'business' => 3];

        return ($order[$newPlan] ?? 0) > ($order[$oldPlan] ?? 0);
    }
}
