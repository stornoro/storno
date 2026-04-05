<?php

namespace App\MessageHandler;

use App\Message\SendSubscriptionConfirmationMessage;
use App\Repository\OrganizationRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

#[AsMessageHandler]
class SendSubscriptionConfirmationHandler
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

    public function __invoke(SendSubscriptionConfirmationMessage $message): void
    {
        if (!$this->mailer) {
            $this->logger->warning('Mailer not configured, skipping subscription confirmation email.');
            return;
        }

        $org = $this->organizationRepository->find($message->organizationId);
        if (!$org) {
            $this->logger->warning('Organization not found for subscription confirmation.', ['orgId' => $message->organizationId]);
            return;
        }

        // Send to all org members
        foreach ($org->getMemberships() as $membership) {
            $user = $membership->getUser();
            if (!$user) {
                continue;
            }

            $locale = $user->getLocale();
            $firstName = $user->getFirstName() ?: $user->getEmail();
            $planLabel = ucfirst($message->planName);
            $amount = number_format($message->amount / 100, 2, '.', '');
            $intervalLabel = $this->translator->trans(
                $message->interval === 'year' ? 'subscription.interval_year' : 'subscription.interval_month',
                [],
                'emails',
                $locale,
            );

            try {
                $html = $this->twig->render('emails/subscription_confirmation.html.twig', [
                    'firstName' => $firstName,
                    'planLabel' => $planLabel,
                    'amount' => $amount,
                    'currency' => strtoupper($message->currency),
                    'intervalLabel' => $intervalLabel,
                    'licenseKey' => $message->licenseKey,
                    'frontendUrl' => rtrim($this->frontendUrl, '/'),
                    'locale' => $locale,
                ]);

                $email = (new Email())
                    ->from($this->mailFrom)
                    ->to($user->getEmail())
                    ->subject($this->translator->trans('subscription.subject', [], 'emails', $locale))
                    ->html($html);

                $email->getHeaders()->addTextHeader('X-Storno-Email-Category', 'subscription_confirmation');
                $this->mailer->send($email);

                $this->logger->info('Subscription confirmation email sent.', [
                    'userId' => (string) $user->getId(),
                    'email' => $user->getEmail(),
                    'plan' => $message->planName,
                ]);
            } catch (\Throwable $e) {
                $this->logger->error('Failed to send subscription confirmation email.', [
                    'userId' => (string) $user->getId(),
                    'email' => $user->getEmail(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
