<?php

namespace App\Service\Balance;

use App\Entity\Company;
use Doctrine\ORM\EntityManagerInterface;

class BalanceAnalysisService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function analyze(Company $company, int $year): array
    {
        $conn = $this->entityManager->getConnection();
        $companyId = (string) $company->getId();

        // Fetch balances for this year
        $balances = $conn->fetchAllAssociative(
            "SELECT id, year, month, status, total_accounts, original_filename, processed_at, created_at
             FROM trial_balance
             WHERE company_id = :companyId AND year = :year AND deleted_at IS NULL
             ORDER BY month ASC",
            ['companyId' => $companyId, 'year' => $year]
        );

        $baseParams = ['companyId' => $companyId, 'year' => $year];

        // Use the latest month's balance for cumulative indicators
        $latestMonth = $conn->fetchOne(
            "SELECT MAX(tb.month) FROM trial_balance tb
             WHERE tb.company_id = :companyId AND tb.year = :year
               AND tb.status = 'completed' AND tb.deleted_at IS NULL",
            $baseParams
        );

        $indicators = $this->computeIndicators($conn, $companyId, $year, $latestMonth ? (int) $latestMonth : null);
        $monthlyEvolution = $this->computeMonthlyEvolution($conn, $companyId, $year);
        $profitability = $this->computeProfitability($indicators);
        $topExpenses = $this->computeTopExpenses($conn, $companyId, $year, $latestMonth ? (int) $latestMonth : null);
        $yoyComparison = $this->computeYoyComparison($conn, $companyId, $year);

        return [
            'year' => $year,
            'balances' => array_map(fn ($b) => [
                'id' => $b['id'],
                'month' => (int) $b['month'],
                'status' => $b['status'],
                'totalAccounts' => (int) $b['total_accounts'],
                'originalFilename' => $b['original_filename'],
                'processedAt' => $b['processed_at'],
                'uploadedAt' => $b['created_at'],
            ], $balances),
            'indicators' => $indicators,
            'monthlyEvolution' => $monthlyEvolution,
            'profitability' => $profitability,
            'topExpenses' => $topExpenses,
            'yoyComparison' => $yoyComparison,
        ];
    }

    /**
     * Compute financial indicators from the latest month's balance.
     *
     * For P&L accounts (class 6 = expenses, class 7 = revenue), we use
     * cumulative TURNOVER columns (current_debit / current_credit) instead
     * of final balance columns. This handles Romanian trial balances where:
     * - Monthly closing entries make finalDebit == finalCredit for P&L accounts
     * - Year-end closing entries zero out P&L final balances entirely
     *
     * For balance sheet accounts (classes 1-5), we use final_debit / final_credit
     * which represent actual account balances at period end.
     */
    private function computeIndicators(
        \Doctrine\DBAL\Connection $conn,
        string $companyId,
        int $year,
        ?int $latestMonth,
    ): array {
        $defaults = [
            'revenue' => '0.00',
            'expenses' => '0.00',
            'netProfit' => '0.00',
            'turnover' => '0.00',
            'salaries' => '0.00',
            'profitTax' => '0.00',
            'supplierDebts' => '0.00',
            'clientReceivables' => '0.00',
            'bankBalance' => '0.00',
            'cashBalance' => '0.00',
        ];

        if ($latestMonth === null) {
            return $defaults;
        }

        $params = ['companyId' => $companyId, 'year' => $year, 'month' => $latestMonth];

        $baseWhere = "FROM trial_balance_row r
                      INNER JOIN trial_balance tb ON r.trial_balance_id = tb.id
                      WHERE tb.company_id = :companyId
                        AND tb.year = :year
                        AND tb.month = :month
                        AND tb.status = 'completed'
                        AND tb.deleted_at IS NULL";

        $row = $conn->fetchAssociative(
            "SELECT
                -- Revenue (class 7): use cumulative credit turnover
                COALESCE(SUM(CASE WHEN r.account_code LIKE '7%' THEN r.current_credit ELSE 0 END), 0) AS revenue,
                -- Expenses (class 6): use cumulative debit turnover
                COALESCE(SUM(CASE WHEN r.account_code LIKE '6%' THEN r.current_debit ELSE 0 END), 0) AS expenses,
                -- Turnover: credit turnover for sales accounts
                COALESCE(SUM(CASE WHEN r.account_code LIKE '70%' OR r.account_code LIKE '71%' THEN r.current_credit ELSE 0 END), 0) AS turnover,
                -- Salaries: debit turnover
                COALESCE(SUM(CASE WHEN r.account_code LIKE '641%' OR r.account_code LIKE '642%' THEN r.current_debit ELSE 0 END), 0) AS salaries,
                -- Profit/income tax: debit turnover (691=profit tax, 698=micro-enterprise income tax)
                COALESCE(SUM(CASE WHEN r.account_code LIKE '691%' OR r.account_code LIKE '698%' THEN r.current_debit ELSE 0 END), 0) AS profit_tax,
                -- Balance sheet accounts: use ABS to handle PDFs where final D/C columns
                -- may be in inconsistent order due to text extraction limitations
                COALESCE(SUM(CASE WHEN r.account_code LIKE '401%' THEN GREATEST(r.final_credit, r.final_debit) ELSE 0 END), 0) AS supplier_debts,
                COALESCE(SUM(CASE WHEN r.account_code LIKE '411%' THEN GREATEST(r.final_debit, r.final_credit) ELSE 0 END), 0) AS client_receivables,
                COALESCE(SUM(CASE WHEN r.account_code LIKE '5121%' THEN GREATEST(r.final_debit, r.final_credit) ELSE 0 END), 0) AS bank_balance,
                COALESCE(SUM(CASE WHEN r.account_code LIKE '5311%' THEN GREATEST(r.final_debit, r.final_credit) ELSE 0 END), 0) AS cash_balance
             {$baseWhere}",
            $params
        );

        if (!$row) {
            return $defaults;
        }

        $revenue = $row['revenue'];
        $expenses = $row['expenses'];
        $netProfit = bcsub($revenue, $expenses, 2);

        return [
            'revenue' => number_format((float) $revenue, 2, '.', ''),
            'expenses' => number_format((float) $expenses, 2, '.', ''),
            'netProfit' => $netProfit,
            'turnover' => number_format((float) $row['turnover'], 2, '.', ''),
            'salaries' => number_format((float) $row['salaries'], 2, '.', ''),
            'profitTax' => number_format((float) $row['profit_tax'], 2, '.', ''),
            'supplierDebts' => number_format((float) $row['supplier_debts'], 2, '.', ''),
            'clientReceivables' => number_format((float) $row['client_receivables'], 2, '.', ''),
            'bankBalance' => number_format((float) $row['bank_balance'], 2, '.', ''),
            'cashBalance' => number_format((float) $row['cash_balance'], 2, '.', ''),
        ];
    }

    /**
     * Monthly evolution: revenue and expenses per month.
     * Uses cumulative turnover columns for P&L accounts.
     */
    private function computeMonthlyEvolution(
        \Doctrine\DBAL\Connection $conn,
        string $companyId,
        int $year,
    ): array {
        $rows = $conn->fetchAllAssociative(
            "SELECT tb.month,
                    COALESCE(SUM(CASE WHEN r.account_code LIKE '7%' THEN r.current_credit ELSE 0 END), 0) AS revenue,
                    COALESCE(SUM(CASE WHEN r.account_code LIKE '6%' THEN r.current_debit ELSE 0 END), 0) AS expenses
             FROM trial_balance_row r
             INNER JOIN trial_balance tb ON r.trial_balance_id = tb.id
             WHERE tb.company_id = :companyId
               AND tb.year = :year
               AND tb.status = 'completed'
               AND tb.deleted_at IS NULL
             GROUP BY tb.month
             ORDER BY tb.month",
            ['companyId' => $companyId, 'year' => $year]
        );

        return array_map(fn ($row) => [
            'month' => (int) $row['month'],
            'revenue' => number_format((float) $row['revenue'], 2, '.', ''),
            'expenses' => number_format((float) $row['expenses'], 2, '.', ''),
            'profit' => number_format((float) $row['revenue'] - (float) $row['expenses'], 2, '.', ''),
        ], $rows);
    }

    private function computeProfitability(array $indicators): array
    {
        $revenue = (float) $indicators['revenue'];
        $expenses = (float) $indicators['expenses'];
        $salaries = (float) $indicators['salaries'];

        if ($revenue == 0) {
            return [
                'profitMargin' => 0,
                'expenseRatio' => 0,
                'salaryRatio' => 0,
            ];
        }

        return [
            'profitMargin' => round((($revenue - $expenses) / $revenue) * 100, 1),
            'expenseRatio' => round(($expenses / $revenue) * 100, 1),
            'salaryRatio' => round(($salaries / $revenue) * 100, 1),
        ];
    }

    /**
     * Top expenses by account group (3-digit prefix).
     * Uses cumulative debit turnover for expense accounts.
     */
    private function computeTopExpenses(
        \Doctrine\DBAL\Connection $conn,
        string $companyId,
        int $year,
        ?int $latestMonth,
    ): array {
        if ($latestMonth === null) {
            return [];
        }

        $rows = $conn->fetchAllAssociative(
            "SELECT LEFT(r.account_code, 3) AS account_group,
                    MIN(r.account_name) AS account_name,
                    COALESCE(SUM(r.current_debit), 0) AS amount
             FROM trial_balance_row r
             INNER JOIN trial_balance tb ON r.trial_balance_id = tb.id
             WHERE tb.company_id = :companyId
               AND tb.year = :year
               AND tb.month = :month
               AND tb.status = 'completed'
               AND tb.deleted_at IS NULL
               AND r.account_code LIKE '6%'
             GROUP BY account_group
             HAVING amount > 0
             ORDER BY amount DESC
             LIMIT 10",
            ['companyId' => $companyId, 'year' => $year, 'month' => $latestMonth]
        );

        // Calculate total expenses for percentage
        $totalExpenses = array_sum(array_map(fn ($r) => (float) $r['amount'], $rows));

        return array_map(fn ($row) => [
            'accountCode' => $row['account_group'],
            'accountName' => $row['account_name'],
            'amount' => number_format((float) $row['amount'], 2, '.', ''),
            'percentage' => $totalExpenses > 0 ? round(((float) $row['amount'] / $totalExpenses) * 100, 1) : 0,
        ], $rows);
    }

    private function computeYoyComparison(
        \Doctrine\DBAL\Connection $conn,
        string $companyId,
        int $year,
    ): array {
        $previousYear = $year - 1;

        $current = $this->getYearTotals($conn, $companyId, $year);
        $previous = $this->getYearTotals($conn, $companyId, $previousYear);

        $changes = [
            'revenue' => $this->percentChange((float) $previous['revenue'], (float) $current['revenue']),
            'expenses' => $this->percentChange((float) $previous['expenses'], (float) $current['expenses']),
            'profit' => $this->percentChange((float) $previous['profit'], (float) $current['profit']),
        ];

        return [
            'currentYear' => $year,
            'previousYear' => $previousYear,
            'current' => $current,
            'previous' => $previous,
            'changes' => $changes,
        ];
    }

    /**
     * Get year totals using cumulative turnover columns for P&L accounts.
     */
    private function getYearTotals(\Doctrine\DBAL\Connection $conn, string $companyId, int $year): array
    {
        $latestMonth = $conn->fetchOne(
            "SELECT MAX(tb.month) FROM trial_balance tb
             WHERE tb.company_id = :companyId AND tb.year = :year
               AND tb.status = 'completed' AND tb.deleted_at IS NULL",
            ['companyId' => $companyId, 'year' => $year]
        );

        if (!$latestMonth) {
            return ['revenue' => '0.00', 'expenses' => '0.00', 'profit' => '0.00'];
        }

        $row = $conn->fetchAssociative(
            "SELECT
                COALESCE(SUM(CASE WHEN r.account_code LIKE '7%' THEN r.current_credit ELSE 0 END), 0) AS revenue,
                COALESCE(SUM(CASE WHEN r.account_code LIKE '6%' THEN r.current_debit ELSE 0 END), 0) AS expenses
             FROM trial_balance_row r
             INNER JOIN trial_balance tb ON r.trial_balance_id = tb.id
             WHERE tb.company_id = :companyId
               AND tb.year = :year
               AND tb.month = :month
               AND tb.status = 'completed'
               AND tb.deleted_at IS NULL",
            ['companyId' => $companyId, 'year' => $year, 'month' => (int) $latestMonth]
        );

        if (!$row) {
            return ['revenue' => '0.00', 'expenses' => '0.00', 'profit' => '0.00'];
        }

        $revenue = (float) $row['revenue'];
        $expenses = (float) $row['expenses'];

        return [
            'revenue' => number_format($revenue, 2, '.', ''),
            'expenses' => number_format($expenses, 2, '.', ''),
            'profit' => number_format($revenue - $expenses, 2, '.', ''),
        ];
    }

    private function percentChange(float $previous, float $current): float
    {
        if ($previous == 0) {
            return $current > 0 ? 100.0 : 0.0;
        }

        return round((($current - $previous) / abs($previous)) * 100, 2);
    }
}
