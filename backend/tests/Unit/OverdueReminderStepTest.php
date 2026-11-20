<?php

namespace App\Tests\Unit;

use App\Entity\Company;
use App\Entity\Invoice;
use App\Service\OverdueReminderService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OverdueReminderStepTest extends TestCase
{
    /**
     * @return iterable<string, array{0: int, 1: ?int, 2: ?int}> days overdue, last step sent, expected step
     */
    public static function cases(): iterable
    {
        yield 'not due yet' => [0, null, null];
        yield 'before the first step' => [2, null, null];
        yield 'on the first step' => [3, null, 3];
        yield 'first step, one day late' => [4, null, 3];
        yield 'first step, at the end of the grace' => [5, null, 3];
        yield 'first step missed for good' => [6, null, null];
        yield 'first step already sent' => [4, 3, null];
        yield 'second step' => [7, 3, 7];
        yield 'second step when the first was never sent' => [8, null, 7];
        yield 'second step already sent' => [9, 7, null];
        yield 'third step' => [14, 7, 14];
        yield 'long overdue history' => [200, null, null];
    }

    #[DataProvider('cases')]
    public function testDueStep(int $daysOverdue, ?int $lastSent, ?int $expected): void
    {
        $today = new \DateTimeImmutable('2026-11-20');
        $invoice = (new Invoice())
            ->setCompany(new Company())
            ->setDueDate(new \DateTime($today->modify(sprintf('-%d days', $daysOverdue))->format('Y-m-d')))
            ->setLastOverdueReminderDays($lastSent);

        $step = OverdueReminderService::dueStep($invoice, $today);

        self::assertSame($expected, $step['days'] ?? null);
    }

    public function testWindowsMatchTheSteps(): void
    {
        $company = (new Company())->setOverdueReminderSteps([['days' => 10], ['days' => 1]]);
        $windows = OverdueReminderService::windowsFor($company, new \DateTimeImmutable('2026-11-20 15:30'));

        self::assertSame(
            [['2026-11-17', '2026-11-19'], ['2026-11-08', '2026-11-10']],
            array_map(static fn (array $w) => [$w[0]->format('Y-m-d'), $w[1]->format('Y-m-d')], $windows),
        );
    }

    public function testStoredStepsThatNoLongerValidateFallBackToTheDefaults(): void
    {
        $company = (new Company())->setOverdueReminderSteps([['days' => 'soon']]);

        self::assertSame([3, 7, 14], array_column(OverdueReminderService::stepsFor($company), 'days'));
    }
}
