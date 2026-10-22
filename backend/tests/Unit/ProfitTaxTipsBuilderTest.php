<?php

namespace App\Tests\Unit;

use App\Entity\Company;
use App\Service\Balance\ProfitTaxTipsBuilder;
use PHPUnit\Framework\TestCase;

/** The hints that go with the profit tax estimated from invoices, sized with the company's numbers. */
class ProfitTaxTipsBuilderTest extends TestCase
{
    private function estimate(string $revenue, string $expenses, bool $runningQuarter = true): array
    {
        $gross = bcsub($revenue, $expenses, 2);
        $tax = bcmul(max('0', $gross), '0.16', 2);
        $share = (float) $revenue > 0 ? round((float) $expenses / (float) $revenue * 100, 1) : null;
        $row = ['revenue' => $revenue, 'invoicedExpenses' => $expenses, 'grossProfit' => $gross, 'estimatedProfitTax' => $tax, 'invoicedExpenseShare' => $share];

        return [
            'year' => 2026,
            'quarters' => [
                $row + ['quarter' => 3, 'from' => '2026-07-01', 'to' => '2026-09-30', 'closed' => true],
                $row + ['quarter' => 4, 'from' => '2026-10-01', 'to' => '2026-12-31', 'closed' => !$runningQuarter],
            ],
            'ytd' => $row,
        ];
    }

    private function company(bool $employees = true, ?string $caen = '6201'): Company
    {
        return (new Company())->setName('X')->setCif(1)->setHasEmployees($employees)->setCaenCode($caen);
    }

    public function testSponsorshipHeadroomIsTheLesserOfTheTwoCaps(): void
    {
        // revenue 923,388 → 0.75 % = 6,925.41; tax on 855,033 = 136,805.28 → 20 % = 27,361.06
        $tips = ProfitTaxTipsBuilder::build($this->estimate('923388.00', '68355.00'), $this->company(), new \DateTimeImmutable('2026-12-10'));
        $byCode = array_column($tips, null, 'code');

        self::assertSame('6925.41', $byCode['sponsorship']['params']['headroom']);
        self::assertSame('27361.06', $byCode['sponsorship']['params']['taxCap']);
        self::assertSame('action', $byCode['sponsorship']['level']);
    }

    public function testReinvestedProfitUsesTheRunningQuarterAndBecomesAnActionNearItsEnd(): void
    {
        $tips = ProfitTaxTipsBuilder::build($this->estimate('100000.00', '20000.00'), $this->company(), new \DateTimeImmutable('2026-12-10'));
        $tip = array_column($tips, null, 'code')['reinvested_profit'];

        self::assertSame('T4 2026', $tip['params']['quarter']);
        self::assertSame(21, $tip['params']['daysLeft']);
        self::assertSame('12800.00', $tip['params']['tax']);
        self::assertSame('80000.00', $tip['params']['maxInvestment']); // 12,800 / 16 %
        self::assertSame('action', $tip['level']);

        $early = ProfitTaxTipsBuilder::build($this->estimate('100000.00', '20000.00'), $this->company(), new \DateTimeImmutable('2026-10-05'));
        self::assertSame('info', array_column($early, null, 'code')['reinvested_profit']['level']);
    }

    public function testMissingExpensesWarningOnlyBelowTheThreshold(): void
    {
        $low = ProfitTaxTipsBuilder::build($this->estimate('100000.00', '7000.00'), $this->company(), new \DateTimeImmutable('2026-11-01'));
        self::assertContains('missing_expenses', array_column($low, 'code'));

        $ok = ProfitTaxTipsBuilder::build($this->estimate('100000.00', '40000.00'), $this->company(), new \DateTimeImmutable('2026-11-01'));
        self::assertNotContains('missing_expenses', array_column($ok, 'code'));
    }

    public function testDividendsEmployeeAndRdTipsDependOnTheCompany(): void
    {
        $tips = ProfitTaxTipsBuilder::build($this->estimate('100000.00', '20000.00'), $this->company(true, '6201'), new \DateTimeImmutable('2026-11-01'));
        $byCode = array_column($tips, null, 'code');
        self::assertSame('67200.00', $byCode['dividends']['params']['netProfit']);  // 80,000 × 0.84
        self::assertSame('10752.00', $byCode['dividends']['params']['dividendTax']); // 16 %
        self::assertSame('56448.00', $byCode['dividends']['params']['available']);
        self::assertArrayHasKey('employee_benefits', $byCode);
        self::assertArrayHasKey('rd_deduction', $byCode);
        self::assertArrayHasKey('advance_payments', $byCode);

        $other = ProfitTaxTipsBuilder::build($this->estimate('100000.00', '120000.00'), $this->company(false, '4711'), new \DateTimeImmutable('2026-11-01'));
        $codes = array_column($other, 'code');
        self::assertNotContains('dividends', $codes);
        self::assertNotContains('employee_benefits', $codes);
        self::assertNotContains('rd_deduction', $codes);
        self::assertNotContains('advance_payments', $codes);
    }
}
