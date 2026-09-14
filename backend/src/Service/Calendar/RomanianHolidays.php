<?php

namespace App\Service\Calendar;

/**
 * Romanian legal holidays (Codul muncii art. 139) and the working-day arithmetic the fiscal
 * deadlines need: a deadline that falls on a Saturday, a Sunday or a legal holiday moves to
 * the next working day (Codul de procedură fiscală art. 75).
 *
 * Fixed dates: 1–2 January, 6–7 January, 24 January, 1 May, 1 June, 15 August, 30 November,
 * 1 December, 25–26 December. Movable, from the Orthodox Easter: Good Friday, Easter Sunday
 * and Monday, Rusalii (Pentecost) Sunday and Monday.
 */
final class RomanianHolidays
{
    private const FIXED = [
        '01-01' => 'Anul Nou',
        '01-02' => 'Anul Nou',
        '01-06' => 'Boboteaza',
        '01-07' => 'Sfântul Ioan Botezătorul',
        '01-24' => 'Unirea Principatelor Române',
        '05-01' => 'Ziua Muncii',
        '06-01' => 'Ziua Copilului',
        '08-15' => 'Adormirea Maicii Domnului',
        '11-30' => 'Sfântul Andrei',
        '12-01' => 'Ziua Națională',
        '12-25' => 'Crăciunul',
        '12-26' => 'Crăciunul',
    ];

    /** @var array<int, array<string, string>> per-year cache of 'Y-m-d' => name */
    private array $byYear = [];

    /**
     * Orthodox Easter Sunday in the Gregorian calendar (Meeus' Julian algorithm, then the
     * 13-day Julian→Gregorian offset valid for 1900–2099).
     */
    public function orthodoxEaster(int $year): \DateTimeImmutable
    {
        $a = $year % 4;
        $b = $year % 7;
        $c = $year % 19;
        $d = (19 * $c + 15) % 30;
        $e = (2 * $a + 4 * $b - $d + 34) % 7;
        $month = intdiv($d + $e + 114, 31);
        $day = (($d + $e + 114) % 31) + 1;

        return (new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day)))->modify('+13 days');
    }

    /** @return array<string, string> 'Y-m-d' => holiday name, sorted by date */
    public function holidays(int $year): array
    {
        if (isset($this->byYear[$year])) {
            return $this->byYear[$year];
        }

        $list = [];
        foreach (self::FIXED as $monthDay => $name) {
            $list[sprintf('%04d-%s', $year, $monthDay)] = $name;
        }

        $easter = $this->orthodoxEaster($year);
        $list[$easter->modify('-2 days')->format('Y-m-d')] = 'Vinerea Mare';
        $list[$easter->format('Y-m-d')] = 'Paștele ortodox';
        $list[$easter->modify('+1 day')->format('Y-m-d')] = 'A doua zi de Paște';
        $list[$easter->modify('+49 days')->format('Y-m-d')] = 'Rusalii';
        $list[$easter->modify('+50 days')->format('Y-m-d')] = 'A doua zi de Rusalii';

        ksort($list);

        return $this->byYear[$year] = $list;
    }

    public function isHoliday(\DateTimeInterface $date): bool
    {
        return isset($this->holidays((int) $date->format('Y'))[$date->format('Y-m-d')]);
    }

    public function holidayName(\DateTimeInterface $date): ?string
    {
        return $this->holidays((int) $date->format('Y'))[$date->format('Y-m-d')] ?? null;
    }

    public function isWeekend(\DateTimeInterface $date): bool
    {
        return (int) $date->format('N') >= 6;
    }

    public function isWorkingDay(\DateTimeInterface $date): bool
    {
        return !$this->isWeekend($date) && !$this->isHoliday($date);
    }

    /** The date itself when it is a working day, otherwise the first working day after it. */
    public function nextWorkingDay(\DateTimeImmutable $date): \DateTimeImmutable
    {
        $day = $date;
        while (!$this->isWorkingDay($day)) {
            $day = $day->modify('+1 day');
        }

        return $day;
    }

    /** The date itself when it is a working day, otherwise the last working day before it. */
    public function previousWorkingDay(\DateTimeImmutable $date): \DateTimeImmutable
    {
        $day = $date;
        while (!$this->isWorkingDay($day)) {
            $day = $day->modify('-1 day');
        }

        return $day;
    }
}
