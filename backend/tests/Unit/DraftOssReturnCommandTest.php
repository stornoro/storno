<?php

namespace App\Tests\Unit;

use App\Command\Declaration\DraftOssReturnCommand;
use PHPUnit\Framework\TestCase;

/** The D398 draft is prepared from the day after a quarter closes until its filing deadline. */
class DraftOssReturnCommandTest extends TestCase
{
    /** @dataProvider days */
    public function testOpenQuarter(string $today, ?array $expected): void
    {
        self::assertSame($expected, DraftOssReturnCommand::openQuarter(new \DateTimeImmutable($today)));
    }

    public static function days(): iterable
    {
        yield 'first day after Q3' => ['2026-10-01', [2026, 3]];
        yield 'deadline day of Q3' => ['2026-10-31', [2026, 3]];
        yield 'after the Q3 deadline' => ['2026-11-15', null];
        yield 'last day of Q3 is still inside Q3' => ['2026-09-30', null];
        yield 'January belongs to the previous year Q4' => ['2027-01-05', [2026, 4]];
        yield 'Q1 window' => ['2026-04-30', [2026, 1]];
        yield 'June: Q1 window closed, Q2 not over' => ['2026-06-10', null];
    }

    public function testParsePeriod(): void
    {
        self::assertSame([2026, 3], DraftOssReturnCommand::parsePeriod('2026-Q3'));
        self::assertSame([2025, 4], DraftOssReturnCommand::parsePeriod('2025q4'));
        $this->expectException(\InvalidArgumentException::class);
        DraftOssReturnCommand::parsePeriod('2026-09');
    }
}
