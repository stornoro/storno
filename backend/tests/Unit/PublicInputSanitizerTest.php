<?php

namespace App\Tests\Unit;

use App\Controller\Api\Auth\RegisterController;
use App\MessageHandler\SendInvitationEmailHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Registration names and organization names flow into outbound emails, so they
 * must not be usable as a phishing carrier.
 */
class PublicInputSanitizerTest extends TestCase
{
    public function testPlainNamesPass(): void
    {
        self::assertSame('Ion Popescu', RegisterController::sanitizeNameInput('  Ion Popescu ', 60));
        self::assertNull(RegisterController::sanitizeNameInput(null, 60));
        self::assertNull(RegisterController::sanitizeNameInput('   ', 60));
        self::assertSame('Café & Co', RegisterController::sanitizeNameInput('Café & Co', 100, rejectAt: false));
    }

    #[DataProvider('rejectedNames')]
    public function testAbusiveNamesAreRejected(mixed $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RegisterController::sanitizeNameInput($value, 60);
    }

    public static function rejectedNames(): iterable
    {
        yield 'url' => ['Claim prize https://evil.example'];
        yield 'www' => ['visit www.evil.example'];
        yield 'email' => ['me@evil.example'];
        yield 'newline' => ["Ion\nPopescu"];
        yield 'carriage return' => ["Ion\rPopescu"];
        yield 'too long' => [str_repeat('a', 61)];
        yield 'non string' => [['x']];
    }

    public function testOrganizationNameAllowsAtButNotLinks(): void
    {
        self::assertSame('Bar @ Home', RegisterController::sanitizeNameInput('Bar @ Home', 100, rejectAt: false));

        $this->expectException(\InvalidArgumentException::class);
        RegisterController::sanitizeNameInput('Bar http://evil.example', 100, rejectAt: false);
    }

    public function testOrganizationNameCapIs100(): void
    {
        self::assertSame(str_repeat('a', 100), RegisterController::sanitizeNameInput(str_repeat('a', 100), 100, rejectAt: false));

        $this->expectException(\InvalidArgumentException::class);
        RegisterController::sanitizeNameInput(str_repeat('a', 101), 100, rejectAt: false);
    }

    public function testInvitationSubjectOrgNameIsStripped(): void
    {
        self::assertSame(
            'Firma Mea SRL',
            SendInvitationEmailHandler::sanitizeOrganizationName("Firma Mea\nSRL https://evil.example/login www.evil.example"),
        );
        self::assertSame('Firma', SendInvitationEmailHandler::sanitizeOrganizationName("Firma\r\n"));
    }
}
