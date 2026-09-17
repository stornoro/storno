<?php

namespace App\Tests\Unit;

use App\Service\Dosar\DosarService;
use PHPUnit\Framework\TestCase;

/**
 * The gross rent of an income year is the monthly rent times the months the contract ran
 * inside that year, so the count has to match what the landlord actually invoiced.
 */
final class RentMonthsTest extends TestCase
{
    private function months(string $from, string $endExclusive): float
    {
        return DosarService::rentMonths(new \DateTimeImmutable($from), new \DateTimeImmutable($endExclusive));
    }

    public function testAContractEndingOnTheFirstDoesNotPayThatMonth(): void
    {
        // 01.12.2023 – 01.12.2024, seen inside 2024: January to November
        self::assertSame(11.0, $this->months('2024-01-01', '2024-12-01'));
        // terminated on 1 March: January and February
        self::assertSame(2.0, $this->months('2024-01-01', '2024-03-01'));
    }

    public function testAContractRunningPastTheYearEndPaysTwelveMonths(): void
    {
        self::assertSame(12.0, $this->months('2024-01-01', '2025-01-01'));
        self::assertSame(9.0, $this->months('2025-04-01', '2026-01-01'));
    }

    public function testAMonthLeftHalfwayCountsProRata(): void
    {
        // five whole months plus 14 of June's 30 days
        self::assertSame(5.4667, $this->months('2024-01-01', '2024-06-15'));
    }

    public function testAnEmptyOrReversedPeriodIsZero(): void
    {
        self::assertSame(0.0, $this->months('2024-01-01', '2024-01-01'));
        self::assertSame(0.0, $this->months('2024-05-01', '2024-01-01'));
    }
}
