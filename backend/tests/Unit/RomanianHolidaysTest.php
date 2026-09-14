<?php

namespace App\Tests\Unit;

use App\Service\Calendar\RomanianHolidays;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RomanianHolidaysTest extends TestCase
{
    private RomanianHolidays $holidays;

    protected function setUp(): void
    {
        $this->holidays = new RomanianHolidays();
    }

    /** @return iterable<string, array{int, string}> */
    public static function orthodoxEasterProvider(): iterable
    {
        yield '2025' => [2025, '2025-04-20'];
        yield '2026' => [2026, '2026-04-12'];
        yield '2027' => [2027, '2027-05-02'];
    }

    #[DataProvider('orthodoxEasterProvider')]
    public function testOrthodoxEaster(int $year, string $expected): void
    {
        self::assertSame($expected, $this->holidays->orthodoxEaster($year)->format('Y-m-d'));
    }

    /** @return iterable<string, array{string}> */
    public static function movableHolidayProvider(): iterable
    {
        // Good Friday, Easter Monday, Rusalii Sunday and Monday
        yield 'Vinerea Mare 2025' => ['2025-04-18'];
        yield 'A doua zi de Paste 2025' => ['2025-04-21'];
        yield 'Rusalii 2025' => ['2025-06-08'];
        yield 'A doua zi de Rusalii 2025' => ['2025-06-09'];
        yield 'Vinerea Mare 2026' => ['2026-04-10'];
        yield 'A doua zi de Paste 2026' => ['2026-04-13'];
        yield 'Rusalii 2026' => ['2026-05-31'];
        yield 'A doua zi de Rusalii 2026' => ['2026-06-01'];
        yield 'Vinerea Mare 2027' => ['2027-04-30'];
        yield 'A doua zi de Paste 2027' => ['2027-05-03'];
        yield 'Rusalii 2027' => ['2027-06-20'];
        yield 'A doua zi de Rusalii 2027' => ['2027-06-21'];
    }

    #[DataProvider('movableHolidayProvider')]
    public function testMovableHolidays(string $date): void
    {
        self::assertTrue($this->holidays->isHoliday(new \DateTimeImmutable($date)), $date);
    }

    public function testFixedHolidaysEveryYear(): void
    {
        foreach ([2025, 2026, 2027] as $year) {
            foreach (['01-01', '01-02', '01-06', '01-07', '01-24', '05-01', '06-01', '08-15', '11-30', '12-01', '12-25', '12-26'] as $monthDay) {
                self::assertTrue($this->holidays->isHoliday(new \DateTimeImmutable("$year-$monthDay")), "$year-$monthDay");
            }
        }
        self::assertFalse($this->holidays->isHoliday(new \DateTimeImmutable('2026-03-25')));
        self::assertFalse($this->holidays->isHoliday(new \DateTimeImmutable('2026-10-26')));
    }

    public function testHolidayListHasNoDuplicatesAndIsSorted(): void
    {
        self::assertCount(17, $this->holidays->holidays(2025)); // 12 fixed + 5 movable
        $list = $this->holidays->holidays(2026);
        self::assertCount(16, $list); // 1 June and Rusalii Monday coincide in 2026
        self::assertSame(array_keys($list), (static function (array $keys) { sort($keys); return $keys; })(array_keys($list)));
        self::assertSame('Ziua Copilului', $this->holidays->holidayName(new \DateTimeImmutable('2025-06-01')));
    }

    public function testWorkingDayArithmetic(): void
    {
        // Saturday → Monday
        self::assertSame('2026-04-27', $this->holidays->nextWorkingDay(new \DateTimeImmutable('2026-04-25'))->format('Y-m-d'));
        // 1 May 2026 is a Friday holiday → Monday 4 May
        self::assertSame('2026-05-04', $this->holidays->nextWorkingDay(new \DateTimeImmutable('2026-05-01'))->format('Y-m-d'));
        // 25 December 2026 (Friday, holiday), 26 (Saturday, holiday), 27 (Sunday) → Monday 28
        self::assertSame('2026-12-28', $this->holidays->nextWorkingDay(new \DateTimeImmutable('2026-12-25'))->format('Y-m-d'));
        // a working day stays put
        self::assertSame('2026-03-25', $this->holidays->nextWorkingDay(new \DateTimeImmutable('2026-03-25'))->format('Y-m-d'));
        // 31 May 2026 is a Sunday, 29 May the last working day of May
        self::assertSame('2026-05-29', $this->holidays->previousWorkingDay(new \DateTimeImmutable('2026-05-31'))->format('Y-m-d'));
        self::assertFalse($this->holidays->isWorkingDay(new \DateTimeImmutable('2027-05-03'))); // Easter Monday 2027
        self::assertTrue($this->holidays->isWorkingDay(new \DateTimeImmutable('2027-05-04')));
    }
}
