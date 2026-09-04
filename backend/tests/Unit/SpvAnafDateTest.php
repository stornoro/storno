<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\Spv\SpvDocumentIngestionService;
use PHPUnit\Framework\TestCase;

final class SpvAnafDateTest extends TestCase
{
    /** @return iterable<string, array{string, ?string}> */
    public static function dates(): iterable
    {
        yield 'SPV day-first with seconds' => ['31082026094216', '2026-08-31 09:42:16'];
        yield 'SPV day-first, 1st of month' => ['01092026000500', '2026-09-01 00:05:00'];
        yield 'e-Factura year-first minutes' => ['202608300905', '2026-08-30 09:05:00'];
        yield 'year-first date only' => ['20260830', '2026-08-30 00:00:00'];
        yield 'day-first with separators' => ['30.07.2026 09:05:36', '2026-07-30 09:05:36'];
        yield 'garbage' => ['abc', null];
        yield 'invalid month never overflows' => ['20262040', null];
    }

    /** @dataProvider dates */
    public function testParse(string $raw, ?string $expected): void
    {
        $d = SpvDocumentIngestionService::parseAnafDate($raw);
        self::assertSame($expected, $d?->format('Y-m-d H:i:s'));
    }
}
