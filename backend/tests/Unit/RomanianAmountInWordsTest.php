<?php

namespace App\Tests\Unit;

use App\Util\RomanianAmountInWords;
use PHPUnit\Framework\TestCase;

class RomanianAmountInWordsTest extends TestCase
{
    /** @dataProvider amounts */
    public function testAmounts(string $amount, string $currency, string $expected): void
    {
        self::assertSame($expected, RomanianAmountInWords::amount($amount, $currency));
    }

    public static function amounts(): iterable
    {
        yield ['1.00', 'RON', 'unu leu'];
        yield ['2.00', 'RON', 'doi lei'];
        yield ['0.50', 'RON', 'zero lei și cincizeci bani'];
        yield ['12.01', 'RON', 'doisprezece lei și unu ban'];
        yield ['21.00', 'RON', 'douăzeci și unu lei'];
        yield ['100.00', 'RON', 'una sută lei'];
        yield ['250.00', 'RON', 'două sute cincizeci lei'];
        yield ['1234.56', 'RON', 'una mie două sute treizeci și patru lei și cincizeci și șase bani'];
        yield ['2000.00', 'RON', 'două mii lei'];
        yield ['21000.00', 'RON', 'douăzeci și una de mii lei'];
        yield ['1000000.00', 'RON', 'un milion lei'];
        yield ['3500000.00', 'RON', 'trei milioane cinci sute de mii lei'];
        yield ['342.61', 'RON', 'trei sute patruzeci și doi lei și șaizeci și unu bani'];
        yield ['45.00', 'EUR', 'patruzeci și cinci euro'];
        yield ['2.50', 'GBP', 'două lire și cincizeci pence'];
    }

    public function testNumbers(): void
    {
        self::assertSame('zero', RomanianAmountInWords::number(0));
        self::assertSame('nouăsprezece', RomanianAmountInWords::number(19));
        self::assertSame('douăsprezece', RomanianAmountInWords::number(12, true));
        self::assertSame('una sută una mii', RomanianAmountInWords::number(101000));
        self::assertSame('un miliard', RomanianAmountInWords::number(1_000_000_000));
    }
}
