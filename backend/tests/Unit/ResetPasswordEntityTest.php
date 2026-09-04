<?php

namespace App\Tests\Unit;

use App\Entity\ResetPassword;
use PHPUnit\Framework\TestCase;

class ResetPasswordEntityTest extends TestCase
{
    public function testDefaultTokenIsStrong(): void
    {
        $a = new ResetPassword();
        $a->prePersistValues();
        $b = new ResetPassword();
        $b->prePersistValues();

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $a->getToken());
        self::assertNotSame($a->getToken(), $b->getToken());
        self::assertNotNull($a->getRequestedAt());
        self::assertGreaterThan($a->getRequestedAt(), $a->getExpiresAt());
    }

    public function testExplicitTokenIsKeptOnPersist(): void
    {
        $reset = (new ResetPassword())->setToken('explicit-token-from-controller');
        $reset->prePersistValues();

        self::assertSame('explicit-token-from-controller', $reset->getToken());
    }
}
