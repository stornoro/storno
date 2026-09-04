<?php

namespace App\Service;

use App\Entity\Organization;
use App\Entity\User;
use App\Exception\EmailSendBlockedException;
use App\Repository\EmailLogRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Abuse guard for user-composed document emails (invoice, delivery note, receipt).
 *
 * Every check runs before the message is handed to the platform mailer:
 *  - plan must allow email sending
 *  - at most MAX_RECIPIENTS addresses per message (to + cc + bcc)
 *  - per-user burst limiter (sliding window, plan-aware)
 *  - per-organization rolling 24h cap, plan-aware, counted from email_log
 *  - subject/body must not look like phishing or carry active HTML
 *
 * Organizations that relay through their own SMTP server (white-label) only get the
 * recipient cap and the HTML check: their reputation, their rules.
 */
class OutboundEmailGuard
{
    public const DOCUMENT_CATEGORIES = ['invoice', 'delivery_note', 'receipt'];

    public const MAX_RECIPIENTS = 5;

    /** Rolling 24h cap per organization, by effective plan. */
    private const DAILY_LIMITS = [
        LicenseManager::PLAN_FREEMIUM => 30,
        LicenseManager::PLAN_STARTER => 300,
        LicenseManager::PLAN_PROFESSIONAL => 1000,
        LicenseManager::PLAN_BUSINESS => 3000,
    ];

    private const PAID_PLANS = [
        LicenseManager::PLAN_STARTER,
        LicenseManager::PLAN_PROFESSIONAL,
        LicenseManager::PLAN_BUSINESS,
    ];

    /** Lower-cased phrases that never belong in an invoice / delivery note / receipt email. */
    private const BLOCKED_PHRASES = [
        'wallet', 'crypto', 'bitcoin', 'ethereum', 'usdt', 'seed phrase', 'recovery phrase',
        'private key', 'metamask', 'trust wallet', 'coinbase', 'binance', 'blockchain',
        'verify your account', 'confirm your account', 'confirm your identity', 'verify your identity',
        'account security', 'security check', 'security verification', 'unusual activity',
        'unusual sign-in', 'your account has been', 'your account will be', 'login attempt',
        'kontosicherheit', 'sicherheitsüberprüfung', 'sicherheitsueberpruefung', 'sicherheitskontrolle',
        'bestätigung erforderlich', 'bestaetigung erforderlich', 'maßnahme erforderlich', 'massnahme erforderlich',
        'ihr konto wurde', 'ihr konto wird',
        'your email subject here', 'lottery', 'you have won', 'claim your',
    ];

    private const BLOCKED_HTML = ['<script', '<iframe', '<form', '<object', '<embed', 'javascript:'];

    public function __construct(
        private readonly LicenseManager $licenseManager,
        private readonly MailerConfigResolver $mailerConfigResolver,
        private readonly EmailLogRepository $emailLogRepository,
        private readonly RateLimiterFactoryInterface $documentEmailFreeLimiter,
        private readonly RateLimiterFactoryInterface $documentEmailPaidLimiter,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param string[]|null $cc
     * @param string[]|null $bcc
     *
     * @throws EmailSendBlockedException
     */
    public function assertCanSend(
        ?Organization $org,
        ?User $sentBy,
        string $category,
        string $to,
        ?array $cc,
        ?array $bcc,
        string $subject,
        string $body,
    ): void {
        $recipients = 1 + \count(array_filter($cc ?? [])) + \count(array_filter($bcc ?? []));
        if ($recipients > self::MAX_RECIPIENTS) {
            $this->block($org, $sentBy, $category, 'recipients', ['count' => $recipients]);
            throw new EmailSendBlockedException(
                sprintf('A document email can have at most %d recipients (to, cc and bcc combined).', self::MAX_RECIPIENTS),
                EmailSendBlockedException::CODE_TOO_MANY_RECIPIENTS,
                Response::HTTP_BAD_REQUEST,
            );
        }

        $haystack = mb_strtolower($subject . "\n" . $body);
        foreach (self::BLOCKED_HTML as $needle) {
            if (str_contains($haystack, $needle)) {
                $this->block($org, $sentBy, $category, 'html', ['needle' => $needle]);
                throw new EmailSendBlockedException(
                    'The email body contains HTML that is not allowed.',
                    EmailSendBlockedException::CODE_CONTENT_BLOCKED,
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                );
            }
        }

        if (!$org) {
            return;
        }

        if (!$this->licenseManager->canSendEmails($org)) {
            throw new EmailSendBlockedException(
                'Email sending is not available on your plan.',
                EmailSendBlockedException::CODE_PLAN_LIMIT,
                Response::HTTP_PAYMENT_REQUIRED,
            );
        }

        // Own SMTP relay: the platform sender reputation is not at stake.
        if ($this->mailerConfigResolver->resolve($org) !== null) {
            return;
        }

        if (preg_match('/[<>\r\n]/', $subject)) {
            $this->block($org, $sentBy, $category, 'subject', ['subject' => mb_substr($subject, 0, 120)]);
            throw new EmailSendBlockedException(
                'The email subject contains characters that are not allowed.',
                EmailSendBlockedException::CODE_CONTENT_BLOCKED,
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        foreach (self::BLOCKED_PHRASES as $phrase) {
            if (str_contains($haystack, $phrase)) {
                $this->block($org, $sentBy, $category, 'phrase', ['phrase' => $phrase, 'subject' => mb_substr($subject, 0, 120)]);
                throw new EmailSendBlockedException(
                    'The email content was rejected by our abuse filter. Document emails must describe the attached document.',
                    EmailSendBlockedException::CODE_CONTENT_BLOCKED,
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                );
            }
        }

        $plan = $this->licenseManager->getEffectivePlan($org);
        $paid = \in_array($plan, self::PAID_PLANS, true);

        if ($sentBy) {
            $factory = $paid ? $this->documentEmailPaidLimiter : $this->documentEmailFreeLimiter;
            $limit = $factory->create('user:' . $sentBy->getId())->consume();
            if (!$limit->isAccepted()) {
                $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());
                $this->block($org, $sentBy, $category, 'burst', ['retryAfter' => $retryAfter]);
                throw new EmailSendBlockedException(
                    'Too many emails sent in a short time. Please wait a few minutes and try again.',
                    EmailSendBlockedException::CODE_RATE_LIMIT,
                    Response::HTTP_TOO_MANY_REQUESTS,
                    $retryAfter,
                );
            }
        }

        $dailyLimit = self::DAILY_LIMITS[$plan] ?? self::DAILY_LIMITS[LicenseManager::PLAN_FREEMIUM];
        $since = new \DateTimeImmutable('-24 hours');
        $sentToday = $this->emailLogRepository->countByOrganizationSince($org, $since, self::DOCUMENT_CATEGORIES);
        if ($sentToday + $recipients > $dailyLimit) {
            $this->block($org, $sentBy, $category, 'daily', ['sent' => $sentToday, 'limit' => $dailyLimit, 'plan' => $plan]);
            throw new EmailSendBlockedException(
                sprintf('Daily email limit reached (%d document emails per 24 hours on your plan).', $dailyLimit),
                EmailSendBlockedException::CODE_DAILY_LIMIT,
                Response::HTTP_TOO_MANY_REQUESTS,
                3600,
            );
        }
    }

    public function getDailyLimit(Organization $org): int
    {
        $plan = $this->licenseManager->getEffectivePlan($org);

        return self::DAILY_LIMITS[$plan] ?? self::DAILY_LIMITS[LicenseManager::PLAN_FREEMIUM];
    }

    private function block(?Organization $org, ?User $user, string $category, string $reason, array $context): void
    {
        $this->logger->warning('Outbound document email blocked', $context + [
            'reason' => $reason,
            'category' => $category,
            'organizationId' => $org?->getId(),
            'userId' => $user?->getId(),
            'userEmail' => $user?->getEmail(),
        ]);
    }
}
