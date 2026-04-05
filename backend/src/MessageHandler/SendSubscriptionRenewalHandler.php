<?php

namespace App\MessageHandler;

use App\Message\SendSubscriptionRenewalMessage;
use App\Repository\OrganizationRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

#[AsMessageHandler]
class SendSubscriptionRenewalHandler
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

    public function __invoke(SendSubscriptionRenewalMessage $message): void
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
            $planLabel = ucfirst($message->planName);
            $amount = number_format($message->amount / 100, 2, '.', '');
            $intervalLabel = $this->translator->trans(
                $message->interval === 'year' ? 'renewal.interval_year' : 'renewal.interval_month',
                [],
                'emails',
                $locale,
            );

            try {
                $html = $this->twig->render('emails/subscription_renewal.html.twig', [
                    'firstName' => $user->getFirstName() ?: $user->getEmail(),
                    'planLabel' => $planLabel,
                    'amount' => $amount,
                    'currency' => strtoupper($message->currency),
                    'intervalLabel' => $intervalLabel,
                    'frontendUrl' => rtrim($this->frontendUrl, '/'),
                    'locale' => $locale,
                ]);

                $email = (new Email())
                    ->from($this->mailFrom)
                    ->to($user->getEmail())
                    ->subject($this->translator->trans('renewal.subject', [], 'emails', $locale))
                    ->html($html);

                $email->getHeaders()->addTextHeader('X-Storno-Email-Category', 'subscription_renewal');
                $this->mailer->send($email);
            } catch (\Throwable $e) {
                $this->logger->error('Failed to send subscription renewal email.', [
                    'email' => $user->getEmail(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
