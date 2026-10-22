<?php

declare(strict_types=1);

namespace App\Service\Balance;

use App\Entity\Company;

/**
 * Practical, data-driven hints that go with the profit tax estimated from invoices:
 * what the law lets a company do with the tax it is about to pay, sized with the
 * company's own numbers. Pure computation, no I/O; the apps translate the codes.
 *
 * Every tip is information for the owner and the accountant, never an action Storno
 * takes: nothing here spends money or files anything.
 */
final class ProfitTaxTipsBuilder
{
    public const PROFIT_TAX_RATE = 0.16;
    public const DIVIDEND_TAX_RATE = 0.16;          // from 1 January 2026
    public const SPONSORSHIP_TURNOVER_CAP = 0.0075; // 0.75 % of turnover
    public const SPONSORSHIP_TAX_CAP = 0.20;        // 20 % of the profit tax
    public const MISSING_EXPENSES_SHARE = 25.0;     // invoiced costs below this share of revenue look incomplete

    /**
     * @param array{year: int, quarters: list<array<string, mixed>>, ytd: array<string, mixed>} $estimate the `profitTaxEstimate` block
     * @return list<array{code: string, level: string, params: array<string, mixed>}>
     */
    public static function build(array $estimate, Company $company, \DateTimeImmutable $today): array
    {
        $tips = [];
        $ytd = $estimate['ytd'];
        $revenue = (float) ($ytd['revenue'] ?? 0);
        $taxYtd = (float) ($ytd['estimatedProfitTax'] ?? 0);
        $gross = (float) ($ytd['grossProfit'] ?? 0);
        $year = (int) $estimate['year'];

        // 1. Sponsorship: the credit is the lesser of 0.75 % of turnover and 20 % of the tax;
        //    unused amounts do not carry over (form 177 can redirect them within 6 months).
        $turnoverCap = max(0.0, $revenue) * self::SPONSORSHIP_TURNOVER_CAP;
        $taxCap = max(0.0, $taxYtd) * self::SPONSORSHIP_TAX_CAP;
        $headroom = min($turnoverCap, $taxCap);
        $tips[] = [
            'code' => 'sponsorship',
            'level' => $headroom > 0 ? 'action' : 'info',
            'params' => [
                'headroom' => self::money($headroom),
                'turnoverCap' => self::money($turnoverCap),
                'taxCap' => self::money($taxCap),
                'year' => $year,
            ],
        ];

        // 2. Reinvested profit: equipment put into service before the quarter closes is exempt
        //    from the tax of that quarter (up to the tax due), so the timing matters.
        foreach ($estimate['quarters'] as $q) {
            if (!empty($q['closed'])) {
                continue;
            }
            $quarterEnd = new \DateTimeImmutable($q['to']);
            $daysLeft = (int) $today->diff($quarterEnd)->format('%r%a');
            $tax = (float) ($q['estimatedProfitTax'] ?? 0);
            $tips[] = [
                'code' => 'reinvested_profit',
                'level' => $tax > 0 && $daysLeft <= 45 ? 'action' : 'info',
                'params' => [
                    'quarter' => sprintf('T%d %d', (int) $q['quarter'], $year),
                    'quarterEnd' => $quarterEnd->format('d.m.Y'),
                    'daysLeft' => max(0, $daysLeft),
                    'tax' => self::money($tax),
                    'maxInvestment' => self::money($tax > 0 ? $tax / self::PROFIT_TAX_RATE : 0),
                ],
            ];
            break;
        }

        // 3. Invoiced costs far below the revenue: external suppliers (card payments, SaaS,
        //    payment-processor fees) are probably missing from Storno and from the books.
        $share = $ytd['invoicedExpenseShare'] ?? null;
        if ($revenue > 0 && $share !== null && (float) $share < self::MISSING_EXPENSES_SHARE) {
            $tips[] = [
                'code' => 'missing_expenses',
                'level' => 'warning',
                'params' => ['share' => (float) $share, 'threshold' => self::MISSING_EXPENSES_SHARE],
            ];
        }

        // 4. What is left for dividends after the profit tax and the dividend tax.
        if ($gross > 0) {
            $netProfit = $gross * (1 - self::PROFIT_TAX_RATE);
            $dividendTax = $netProfit * self::DIVIDEND_TAX_RATE;
            $tips[] = [
                'code' => 'dividends',
                'level' => 'info',
                'params' => [
                    'netProfit' => self::money($netProfit),
                    'dividendTax' => self::money($dividendTax),
                    'available' => self::money($netProfit - $dividendTax),
                    'dividendTaxRate' => (int) round(self::DIVIDEND_TAX_RATE * 100),
                ],
            ];
        }

        // 5. Tax-free employee benefits, deductible for the company (within the 33 % cap).
        if ($company->hasEmployees()) {
            $tips[] = ['code' => 'employee_benefits', 'level' => 'info', 'params' => []];
        }

        // 6. R&D: the 50 % extra deduction on eligible costs, for software / IT companies.
        $caen = (string) $company->getCaenCode();
        if ($caen !== '' && in_array(substr($caen, 0, 2), ['62', '63', '72'], true)) {
            $tips[] = ['code' => 'rd_deduction', 'level' => 'info', 'params' => []];
        }

        // 7. Quarterly advance payments equal to last year's tax, when this year is weaker.
        if ($gross > 0) {
            $tips[] = ['code' => 'advance_payments', 'level' => 'info', 'params' => []];
        }

        return $tips;
    }

    private static function money(float $value): string
    {
        return number_format(round($value, 2), 2, '.', '');
    }
}
