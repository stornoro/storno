<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Util\Cnp;
use PHPUnit\Framework\TestCase;

final class CnpTest extends TestCase
{
    public function testChecksumAndShape(): void
    {
        self::assertTrue(Cnp::isValid('1800101400016'));
        self::assertTrue(Cnp::isValid(' 1800101400016 '));
        self::assertFalse(Cnp::isValid('1800101400017'), 'wrong control digit');
        self::assertFalse(Cnp::isValid('9800101400016'), 'NIF (9…) is not a resident CNP');
        self::assertFalse(Cnp::isValid('12345678'), 'a CUI');
        self::assertFalse(Cnp::isValid(null));
        self::assertTrue(Cnp::looksLikeNaturalPerson('RO1800101400016'));
        self::assertFalse(Cnp::looksLikeNaturalPerson('9800101400016'));
        self::assertFalse(Cnp::looksLikeNaturalPerson('39225564'));
    }
}
