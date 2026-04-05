<?php

namespace App\MessageHandler;

use App\Message\SendSubscriptionCancelledMessage;
use App\Repository\OrganizationRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

#[AsMessageHandler]
class SendSubscriptionCancelledHandler
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

    public function __invoke(SendSubscriptionCancelledMessage $message): void
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

            try {
                $html = $this->twig->render('emails/subscription_cancelled.html.twig', [
                    'firstName' => $user->getFirstName() ?: $user->getEmail(),
                    'previousPlan' => ucfirst($message->previousPlan),
                    'frontendUrl' => rtrim($this->frontendUrl, '/'),
                    'locale' => $locale,
                ]);

                $email = (new Email())
                    ->from($this->mailFrom)
                    ->to($user->getEmail())
                    ->subject($this->translator->trans('cancelled.subject', [], 'emails', $locale))
                    ->html($html);

                $email->getHeaders()->addTextHeader('X-Storno-Email-Category', 'subscription_cancelled');
                $this->mailer->send($email);
            } catch (\Throwable $e) {
                $this->logger->error('Failed to send subscription cancelled email.', [
                    'email' => $user->getEmail(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
