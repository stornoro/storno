<?php

namespace App\Tests\Unit;

use App\Entity\Organization;
use App\Entity\User;
use App\Exception\EmailSendBlockedException;
use App\Repository\EmailLogRepository;
use App\Service\LicenseManager;
use App\Service\MailerConfigResolver;
use App\Service\OutboundEmailGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * The guard sits in front of every user-composed document email. It must
 * stop the Aug 2026 abuse pattern (phishing wording, thousands of recipients
 * from one freemium account) without touching ordinary invoice emails.
 */
class OutboundEmailGuardTest extends TestCase
{
    private function makeGuard(
        string $plan = LicenseManager::PLAN_FREEMIUM,
        bool $canSend = true,
        bool $ownSmtp = false,
        bool $burstAccepted = true,
        int $sentLast24h = 0,
    ): OutboundEmailGuard {
        $license = $this->createMock(LicenseManager::class);
        $license->method('canSendEmails')->willReturn($canSend);
        $license->method('getEffectivePlan')->willReturn($plan);

        $resolver = $this->createMock(MailerConfigResolver::class);
        $resolver->method('resolve')->willReturn($ownSmtp ? ['host' => 'smtp.example.com'] : null);

        $repo = $this->createMock(EmailLogRepository::class);
        $repo->method('countByOrganizationSince')->willReturn($sentLast24h);

        $limit = new RateLimit(
            $burstAccepted ? 5 : 0,
            new \DateTimeImmutable('+300 seconds'),
            $burstAccepted,
            10,
        );
        $limiter = $this->createMock(LimiterInterface::class);
        $limiter->method('consume')->willReturn($limit);
        $factory = $this->createMock(RateLimiterFactoryInterface::class);
        $factory->method('create')->willReturn($limiter);

        return new OutboundEmailGuard($license, $resolver, $repo, $factory, $factory, new NullLogger());
    }

    private function send(OutboundEmailGuard $guard, string $subject = 'Factura FCT0001 - Acme SRL', string $body = "Buna ziua,\n\nVa trimitem atasat factura FCT0001.", ?array $cc = null, ?array $bcc = null): void
    {
        $guard->assertCanSend(new Organization(), new User(), 'invoice', 'client@example.com', $cc, $bcc, $subject, $body);
    }

    public function testOrdinaryInvoiceEmailPasses(): void
    {
        $this->send($this->makeGuard());
        $this->addToAssertionCount(1);
    }

    public function testPhishingSubjectIsBlocked(): void
    {
        $this->expectException(EmailSendBlockedException::class);
        $this->expectExceptionMessageMatches('/abuse filter/');
        $this->send($this->makeGuard(), 'Confirm Your wallet ownership');
    }

    public function testGermanBankPhishingIsBlocked(): void
    {
        try {
            $this->send($this->makeGuard(), 'Ihre Kontosicherheit erfordert Maßnahmen');
            self::fail('expected block');
        } catch (EmailSendBlockedException $e) {
            self::assertSame(EmailSendBlockedException::CODE_CONTENT_BLOCKED, $e->errorCode);
            self::assertSame(422, $e->httpStatus);
        }
    }

    public function testActiveHtmlInBodyIsBlockedEvenWithOwnSmtp(): void
    {
        $this->expectException(EmailSendBlockedException::class);
        $this->send($this->makeGuard(ownSmtp: true), 'Factura', '<form action="https://evil.example"><input name="pw"></form>');
    }

    public function testOwnSmtpSkipsWordingAndVolumeChecks(): void
    {
        $this->send($this->makeGuard(ownSmtp: true, burstAccepted: false, sentLast24h: 99999), 'Wallet reminder');
        $this->addToAssertionCount(1);
    }

    public function testTooManyRecipients(): void
    {
        try {
            $this->send($this->makeGuard(), cc: ['a@x.ro', 'b@x.ro', 'c@x.ro'], bcc: ['d@x.ro', 'e@x.ro']);
            self::fail('expected block');
        } catch (EmailSendBlockedException $e) {
            self::assertSame(EmailSendBlockedException::CODE_TOO_MANY_RECIPIENTS, $e->errorCode);
            self::assertSame(400, $e->httpStatus);
        }
    }

    public function testPlanWithoutEmailSending(): void
    {
        try {
            $this->send($this->makeGuard(plan: LicenseManager::PLAN_EXPIRED, canSend: false));
            self::fail('expected block');
        } catch (EmailSendBlockedException $e) {
            self::assertSame(EmailSendBlockedException::CODE_PLAN_LIMIT, $e->errorCode);
            self::assertSame(402, $e->httpStatus);
        }
    }

    public function testBurstLimitReturnsRetryAfter(): void
    {
        try {
            $this->send($this->makeGuard(burstAccepted: false));
            self::fail('expected block');
        } catch (EmailSendBlockedException $e) {
            self::assertSame(EmailSendBlockedException::CODE_RATE_LIMIT, $e->errorCode);
            self::assertSame(429, $e->httpStatus);
            self::assertGreaterThan(0, $e->retryAfter);
        }
    }

    public function testFreemiumDailyCap(): void
    {
        $this->send($this->makeGuard(sentLast24h: 29));
        $this->addToAssertionCount(1);

        try {
            $this->send($this->makeGuard(sentLast24h: 30));
            self::fail('expected block');
        } catch (EmailSendBlockedException $e) {
            self::assertSame(EmailSendBlockedException::CODE_DAILY_LIMIT, $e->errorCode);
        }
    }

    public function testPaidPlanHasHigherDailyCap(): void
    {
        $this->send($this->makeGuard(plan: LicenseManager::PLAN_PROFESSIONAL, sentLast24h: 500));
        $this->addToAssertionCount(1);
    }
}
