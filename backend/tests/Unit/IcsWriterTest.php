<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\Calendar\CalendarFeedService;
use App\Service\Calendar\IcsWriter;
use PHPUnit\Framework\TestCase;

final class IcsWriterTest extends TestCase
{
    public function testEscapesTextValues(): void
    {
        self::assertSame('RCA\\, ITP\; rovinieta\\nrand nou \\\\ slash', IcsWriter::escape("RCA, ITP; rovinieta\nrand nou \\ slash"));
    }

    public function testAlarmTriggersFireAtNineInTheMorning(): void
    {
        self::assertSame('PT9H', IcsWriter::trigger(0), 'the same day at 09:00');
        self::assertSame('-PT15H', IcsWriter::trigger(1), 'the day before at 09:00');
        self::assertSame('-P29DT15H', IcsWriter::trigger(30));
    }

    public function testLongLinesFoldWithoutSplittingUtf8Characters(): void
    {
        $line = 'SUMMARY:' . str_repeat('ăîșțâ', 30);
        $folded = IcsWriter::fold($line);
        foreach (explode("\r\n", $folded) as $i => $part) {
            self::assertLessThanOrEqual(75, strlen($part), "line $i is at most 75 octets");
            self::assertTrue(mb_check_encoding($part, 'UTF-8'), "line $i is valid UTF-8");
            if ($i > 0) {
                self::assertSame(' ', $part[0], 'continuation lines start with a space');
            }
        }
        self::assertSame($line, str_replace("\r\n ", '', $folded), 'unfolding gives the original line back');
    }

    public function testRendersAllDayEventsWithAlarmsAndCrlf(): void
    {
        $ics = new IcsWriter('Storno · Exemplu SRL');
        $ics->addAllDayEvent('expiry-1@storno.ro', new \DateTimeImmutable('2026-10-31'), 'Expiră RCA · B 01 TST', "Firma: Exemplu SRL\nNumăr: POL-1", 'https://app.example.test/vehicles/1', [30, 1], new \DateTimeImmutable('2026-10-05 10:00:00', new \DateTimeZone('UTC')));
        $body = $ics->render();

        self::assertStringStartsWith("BEGIN:VCALENDAR\r\nVERSION:2.0\r\n", $body);
        self::assertStringEndsWith("END:VCALENDAR\r\n", $body);
        self::assertStringNotContainsString("\n\n", str_replace("\r\n", "\n", $body) . 'x');
        self::assertStringContainsString("DTSTART;VALUE=DATE:20261031\r\nDTEND;VALUE=DATE:20261101\r\n", $body);
        self::assertStringContainsString("DTSTAMP:20261005T100000Z\r\n", $body);
        self::assertStringContainsString('DESCRIPTION:Firma: Exemplu SRL\\nNumăr: POL-1', $body);
        self::assertSame(2, substr_count($body, 'BEGIN:VALARM'));
        self::assertStringContainsString("TRIGGER:-P29DT15H\r\n", $body);
        self::assertStringContainsString("TRIGGER:-PT15H\r\n", $body);
        self::assertSame(0, preg_match("/(?<!\r)\n/", $body), 'every line ends in CRLF');
    }

    public function testPeriodLabels(): void
    {
        self::assertSame('septembrie 2026', CalendarFeedService::periodLabel(['year' => 2026, 'month' => 9]));
        self::assertSame('trimestrul 3 2026', CalendarFeedService::periodLabel(['year' => 2026, 'quarter' => 3]));
        self::assertSame('anul 2025', CalendarFeedService::periodLabel(['year' => 2025]));
        self::assertNull(CalendarFeedService::periodLabel([]));
    }
}
