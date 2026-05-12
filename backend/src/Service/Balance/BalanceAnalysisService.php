<?php

namespace App\Service\Balance;

use App\Entity\Company;
use App\Service\ExchangeRateService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

class BalanceAnalysisService
{
    /**
     * 2025 Romanian thresholds. Keep here so a single edit moves the
     * needles when the Legea micro / Codul fiscal plafons change.
     */
    private const MICRO_THRESHOLD_EUR = 250000.0;
    private const VAT_THRESHOLD_RON   = 300000.0;
    private const SAFE_RUNWAY_MONTHS  = 6.0;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ExchangeRateService $exchangeRateService,
    ) {}

    public function analyze(Company $company, int $year): array
    {
        $conn = $this->entityManager->getConnection();
        $companyId = (string) $company->getId();

        $balances = $conn->fetchAllAssociative(
            "SELECT id, year, month, status, total_accounts, original_filename, processed_at, created_at
             FROM trial_balance
             WHERE company_id = :companyId AND year = :year AND deleted_at IS NULL
             ORDER BY month ASC",
            ['companyId' => $companyId, 'year' => $year]
        );

        $baseParams = ['companyId' => $companyId, 'year' => $year];

        $latestMonth = $conn->fetchOne(
            "SELECT MAX(tb.month) FROM trial_balance tb
             WHERE tb.company_id = :companyId AND tb.year = :year
               AND tb.status = 'completed' AND tb.deleted_at IS NULL",
            $baseParams
        );
        $latestMonth = $latestMonth ? (int) $latestMonth : null;

        $sheet = $this->computeBalanceSheet($conn, $companyId, $year, $latestMonth);
        $indicators = $this->indicatorsFromSheet($sheet);
        $monthlyEvolution = $this->computeMonthlyEvolution($conn, $companyId, $year);
        $profitability = $this->computeProfitability($indicators);
        $topExpenses = $this->computeTopExpenses($conn, $companyId, $year, $latestMonth);
        $yoyComparison = $this->computeYoyComparison($conn, $companyId, $year);

        $liquidity = $this->computeLiquidityRatios($sheet);
        $solvency = $this->computeSolvencyRatios($sheet);
        $profitabilityRatios = $this->computeProfitabilityRatios($sheet);
        $efficiency = $this->computeEfficiencyRatios($sheet, $latestMonth);
        $fiscal = $this->computeFiscalIndicators($company, $sheet);
        $cashflow = $this->computeCashflowIndicators($sheet, $monthlyEvolution, $latestMonth);
        $aging = $this->computeReceivablesAging($conn, $companyId);
        $concentration = $this->computeClientConcentration($conn, $companyId, $year);

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
            'balanceSheet' => $this->serializeBalanceSheet($sheet),
            'liquidity' => $liquidity,
            'solvency' => $solvency,
            'profitabilityRatios' => $profitabilityRatios,
            'efficiency' => $efficiency,
            'fiscal' => $fiscal,
            'cashflow' => $cashflow,
            'aging' => $aging,
            'concentration' => $concentration,
        ];
    }

    /**
     * Pull every figure we'll need into one named bag. Class 6/7 use
     * cumulative turnover (current_debit/current_credit); balance-sheet
     * classes 1-5 use final_debit/final_credit with GREATEST to absorb
     * PDF text-extraction column flips.
     */
    private function computeBalanceSheet(
        Connection $conn,
        string $companyId,
        int $year,
        ?int $latestMonth,
    ): array {
        $empty = [
            'hasData' => false, 'latestMonth' => null,
            'revenue' => 0.0, 'expenses' => 0.0, 'turnover' => 0.0, 'netProfit' => 0.0,
            'cogs' => 0.0, 'servicesExpense' => 0.0, 'salaries' => 0.0,
            'depreciation' => 0.0, 'interestExpense' => 0.0,
            'profitTax' => 0.0, 'microTax' => 0.0,
            'inventory' => 0.0, 'receivables' => 0.0, 'cash' => 0.0, 'bank' => 0.0, 'securities' => 0.0,
            'fixedAssets' => 0.0,
            'currentAssets' => 0.0, 'totalAssets' => 0.0,
            'supplierDebts' => 0.0, 'salaryDebts' => 0.0, 'taxDebts' => 0.0,
            'vatPayable' => 0.0, 'shortTermDebt' => 0.0,
            'currentLiabilities' => 0.0,
            'longTermDebt' => 0.0,
            'totalLiabilities' => 0.0,
            'equity' => 0.0,
            'isMicro' => false,
        ];

        if ($latestMonth === null) {
            return $empty;
        }

        $params = ['companyId' => $companyId, 'year' => $year, 'month' => $latestMonth];

        $where = "FROM trial_balance_row r
                  INNER JOIN trial_balance tb ON r.trial_balance_id = tb.id
                  WHERE tb.company_id = :companyId
                    AND tb.year = :year
                    AND tb.month = :month
                    AND tb.status = 'completed'
                    AND tb.deleted_at IS NULL";

        $bs = "GREATEST(r.final_debit, r.final_credit)";
        $bsDebit = "GREATEST(r.final_debit - r.final_credit, 0)";
        $bsCredit = "GREATEST(r.final_credit - r.final_debit, 0)";

        $sql = "
            SELECT
                -- P&L
                COALESCE(SUM(CASE WHEN r.account_code LIKE '7%' THEN r.current_credit ELSE 0 END), 0) AS revenue,
                COALESCE(SUM(CASE WHEN r.account_code LIKE '6%' THEN r.current_debit ELSE 0 END), 0) AS expenses,
                COALESCE(SUM(CASE WHEN r.account_code LIKE '70%' OR r.account_code LIKE '71%' THEN r.current_credit ELSE 0 END), 0) AS turnover,
                COALESCE(SUM(CASE WHEN r.account_code LIKE '60%' THEN r.current_debit ELSE 0 END), 0) AS cogs,
                COALESCE(SUM(CASE WHEN r.account_code LIKE '61%' OR r.account_code LIKE '62%' THEN r.current_debit ELSE 0 END), 0) AS services_expense,
                COALESCE(SUM(CASE WHEN r.account_code LIKE '641%' OR r.account_code LIKE '642%' THEN r.current_debit ELSE 0 END), 0) AS salaries,
                COALESCE(SUM(CASE WHEN r.account_code LIKE '681%' THEN r.current_debit ELSE 0 END), 0) AS depreciation,
                COALESCE(SUM(CASE WHEN r.account_code LIKE '666%' THEN r.current_debit ELSE 0 END), 0) AS interest_expense,
                COALESCE(SUM(CASE WHEN r.account_code LIKE '691%' THEN r.current_debit ELSE 0 END), 0) AS profit_tax,
                COALESCE(SUM(CASE WHEN r.account_code LIKE '698%' THEN r.current_debit ELSE 0 END), 0) AS micro_tax,
                -- Active circulante
                COALESCE(SUM(CASE WHEN r.account_code LIKE '3%' AND r.account_code NOT LIKE '39%' THEN $bsDebit ELSE 0 END), 0) AS inventory,
                COALESCE(SUM(CASE WHEN r.account_code LIKE '411%' OR r.account_code LIKE '413%' OR r.account_code LIKE '418%' OR r.account_code LIKE '461%' THEN $bsDebit ELSE 0 END), 0) AS receivables,
                COALESCE(SUM(CASE WHEN r.account_code LIKE '5311%' OR r.account_code LIKE '5314%' OR r.account_code LIKE '532%' THEN $bsDebit ELSE 0 END), 0) AS cash,
                COALESCE(SUM(CASE WHEN r.account_code LIKE '5121%' OR r.account_code LIKE '5124%' OR r.account_code LIKE '5125%' THEN $bsDebit ELSE 0 END), 0) AS bank,
                COALESCE(SUM(CASE WHEN r.account_code LIKE '501%' OR r.account_code LIKE '505%' OR r.account_code LIKE '508%' THEN $bsDebit ELSE 0 END), 0) AS securities,
                -- Active imobilizate (net: clase 20-26 minus amortizare/provizioane 28/29)
                COALESCE(SUM(CASE WHEN (r.account_code LIKE '20%' OR r.account_code LIKE '21%' OR r.account_code LIKE '22%' OR r.account_code LIKE '23%' OR r.account_code LIKE '26%') THEN $bsDebit
                                  WHEN (r.account_code LIKE '28%' OR r.account_code LIKE '29%') THEN -1 * $bsCredit
                                  ELSE 0 END), 0) AS fixed_assets,
                -- Datorii curente
                COALESCE(SUM(CASE WHEN r.account_code LIKE '401%' OR r.account_code LIKE '403%' OR r.account_code LIKE '404%' OR r.account_code LIKE '405%' OR r.account_code LIKE '408%' OR r.account_code LIKE '419%' THEN $bsCredit ELSE 0 END), 0) AS supplier_debts,
                COALESCE(SUM(CASE WHEN r.account_code LIKE '421%' OR r.account_code LIKE '423%' OR r.account_code LIKE '424%' OR r.account_code LIKE '425%' OR r.account_code LIKE '426%' OR r.account_code LIKE '427%' OR r.account_code LIKE '428%' OR r.account_code LIKE '431%' OR r.account_code LIKE '436%' OR r.account_code LIKE '437%' OR r.account_code LIKE '438%' THEN $bsCredit ELSE 0 END), 0) AS salary_debts,
                COALESCE(SUM(CASE WHEN r.account_code LIKE '441%' OR r.account_code LIKE '444%' OR r.account_code LIKE '446%' OR r.account_code LIKE '447%' OR r.account_code LIKE '448%' THEN $bsCredit ELSE 0 END), 0) AS tax_debts,
                COALESCE(SUM(CASE WHEN r.account_code LIKE '4423%' THEN $bsCredit
                                  WHEN r.account_code LIKE '4424%' THEN -1 * $bsDebit
                                  ELSE 0 END), 0) AS vat_payable,
                COALESCE(SUM(CASE WHEN r.account_code LIKE '519%' OR r.account_code LIKE '5191%' OR r.account_code LIKE '5198%' THEN $bsCredit ELSE 0 END), 0) AS short_term_debt,
                -- Datorii pe termen lung
                COALESCE(SUM(CASE WHEN r.account_code LIKE '161%' OR r.account_code LIKE '162%' OR r.account_code LIKE '166%' OR r.account_code LIKE '167%' OR r.account_code LIKE '168%' THEN $bsCredit ELSE 0 END), 0) AS long_term_debt,
                -- Capitaluri proprii (clase 10-12, mai puțin 129 repartizarea)
                COALESCE(SUM(CASE WHEN (r.account_code LIKE '101%' OR r.account_code LIKE '104%' OR r.account_code LIKE '105%' OR r.account_code LIKE '106%' OR r.account_code LIKE '107%' OR r.account_code LIKE '117%' OR r.account_code LIKE '121%') THEN $bsCredit - $bsDebit
                                  WHEN r.account_code LIKE '129%' THEN -1 * ($bsCredit - $bsDebit)
                                  ELSE 0 END), 0) AS equity
            $where
        ";

        $row = $conn->fetchAssociative($sql, $params) ?: [];
        $f = fn (string $k) => (float) ($row[$k] ?? 0);

        $revenue = $f('revenue');
        $expenses = $f('expenses');
        $cash = $f('cash');
        $bank = $f('bank');
        $securities = $f('securities');
        $inventory = $f('inventory');
        $receivables = $f('receivables');
        $fixedAssets = max(0.0, $f('fixed_assets'));

        $currentAssets = $cash + $bank + $securities + $inventory + $receivables;
        $totalAssets = $currentAssets + $fixedAssets;

        $supplierDebts = $f('supplier_debts');
        $salaryDebts = $f('salary_debts');
        $taxDebts = $f('tax_debts');
        $shortTermDebt = $f('short_term_debt');
        $currentLiabilities = $supplierDebts + $salaryDebts + $taxDebts + $shortTermDebt;
        $longTermDebt = $f('long_term_debt');
        $totalLiabilities = $currentLiabilities + $longTermDebt;

        return [
            'hasData' => true,
            'latestMonth' => $latestMonth,
            'revenue' => $revenue,
            'expenses' => $expenses,
            'turnover' => $f('turnover'),
            'netProfit' => $revenue - $expenses,
            'cogs' => $f('cogs'),
            'servicesExpense' => $f('services_expense'),
            'salaries' => $f('salaries'),
            'depreciation' => $f('depreciation'),
            'interestExpense' => $f('interest_expense'),
            'profitTax' => $f('profit_tax'),
            'microTax' => $f('micro_tax'),
            'inventory' => $inventory,
            'receivables' => $receivables,
            'cash' => $cash,
            'bank' => $bank,
            'securities' => $securities,
            'fixedAssets' => $fixedAssets,
            'currentAssets' => $currentAssets,
            'totalAssets' => $totalAssets,
            'supplierDebts' => $supplierDebts,
            'salaryDebts' => $salaryDebts,
            'taxDebts' => $taxDebts,
            'vatPayable' => $f('vat_payable'),
            'shortTermDebt' => $shortTermDebt,
            'currentLiabilities' => $currentLiabilities,
            'longTermDebt' => $longTermDebt,
            'totalLiabilities' => $totalLiabilities,
            'equity' => $f('equity'),
            'isMicro' => $f('micro_tax') > 0 && $f('profit_tax') === 0.0,
        ];
    }

    /**
     * Backwards-compat 10-key bag still consumed by the old KPI grid and
     * by /v1/balances/analysis clients that don't know the new sub-objects.
     */
    private function indicatorsFromSheet(array $s): array
    {
        if (!$s['hasData']) {
            return [
                'revenue' => '0.00', 'expenses' => '0.00', 'netProfit' => '0.00',
                'turnover' => '0.00', 'salaries' => '0.00', 'profitTax' => '0.00',
                'supplierDebts' => '0.00', 'clientReceivables' => '0.00',
                'bankBalance' => '0.00', 'cashBalance' => '0.00',
            ];
        }

        return [
            'revenue' => self::money($s['revenue']),
            'expenses' => self::money($s['expenses']),
            'netProfit' => self::money($s['netProfit']),
            'turnover' => self::money($s['turnover']),
            'salaries' => self::money($s['salaries']),
            'profitTax' => self::money($s['profitTax'] + $s['microTax']),
            'supplierDebts' => self::money($s['supplierDebts']),
            'clientReceivables' => self::money($s['receivables']),
            'bankBalance' => self::money($s['bank']),
            'cashBalance' => self::money($s['cash']),
        ];
    }

    private function serializeBalanceSheet(array $s): array
    {
        $keys = [
            'currentAssets', 'fixedAssets', 'totalAssets',
            'inventory', 'receivables', 'cash', 'bank', 'securities',
            'currentLiabilities', 'longTermDebt', 'totalLiabilities',
            'supplierDebts', 'salaryDebts', 'taxDebts', 'vatPayable', 'shortTermDebt',
            'equity', 'depreciation', 'interestExpense',
            'cogs', 'servicesExpense',
        ];
        $out = ['hasData' => $s['hasData']];
        foreach ($keys as $k) {
            $out[$k] = self::money($s[$k] ?? 0);
        }
        return $out;
    }

    private function computeMonthlyEvolution(
        Connection $conn,
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
            'revenue' => self::money((float) $row['revenue']),
            'expenses' => self::money((float) $row['expenses']),
            'profit' => self::money((float) $row['revenue'] - (float) $row['expenses']),
        ], $rows);
    }

    private function computeProfitability(array $indicators): array
    {
        $revenue = (float) $indicators['revenue'];
        $expenses = (float) $indicators['expenses'];
        $salaries = (float) $indicators['salaries'];

        if ($revenue == 0) {
            return ['profitMargin' => 0, 'expenseRatio' => 0, 'salaryRatio' => 0];
        }

        return [
            'profitMargin' => round((($revenue - $expenses) / $revenue) * 100, 1),
            'expenseRatio' => round(($expenses / $revenue) * 100, 1),
            'salaryRatio'  => round(($salaries / $revenue) * 100, 1),
        ];
    }

    private function computeTopExpenses(
        Connection $conn,
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

        $totalExpenses = array_sum(array_map(fn ($r) => (float) $r['amount'], $rows));

        return array_map(fn ($row) => [
            'accountCode' => $row['account_group'],
            'accountName' => $row['account_name'],
            'amount' => self::money((float) $row['amount']),
            'percentage' => $totalExpenses > 0 ? round(((float) $row['amount'] / $totalExpenses) * 100, 1) : 0,
        ], $rows);
    }

    private function computeYoyComparison(
        Connection $conn,
        string $companyId,
        int $year,
    ): array {
        $previousYear = $year - 1;
        $current = $this->getYearTotals($conn, $companyId, $year);
        $previous = $this->getYearTotals($conn, $companyId, $previousYear);

        return [
            'currentYear' => $year,
            'previousYear' => $previousYear,
            'current' => $current,
            'previous' => $previous,
            'changes' => [
                'revenue' => $this->percentChange((float) $previous['revenue'], (float) $current['revenue']),
                'expenses' => $this->percentChange((float) $previous['expenses'], (float) $current['expenses']),
                'profit' => $this->percentChange((float) $previous['profit'], (float) $current['profit']),
            ],
        ];
    }

    private function getYearTotals(Connection $conn, string $companyId, int $year): array
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
            'revenue' => self::money($revenue),
            'expenses' => self::money($expenses),
            'profit' => self::money($revenue - $expenses),
        ];
    }

    private function percentChange(float $previous, float $current): float
    {
        if ($previous == 0) {
            return $current > 0 ? 100.0 : 0.0;
        }

        return round((($current - $previous) / abs($previous)) * 100, 2);
    }

    // ─── New: grouped ratio sections ────────────────────────────────────

    private function computeLiquidityRatios(array $s): array
    {
        if (!$s['hasData']) {
            return ['hasData' => false];
        }

        $cl = $s['currentLiabilities'];
        $currentRatio = $cl > 0 ? $s['currentAssets'] / $cl : null;
        $quickRatio = $cl > 0 ? ($s['currentAssets'] - $s['inventory']) / $cl : null;
        $cash = $s['cash'] + $s['bank'] + $s['securities'];
        $cashRatio = $cl > 0 ? $cash / $cl : null;
        $workingCapital = $s['currentAssets'] - $cl;

        $longTerm = $s['longTermDebt'];
        $permanentCapital = $s['equity'] + $longTerm;
        $workingCapitalLT = $permanentCapital - $s['fixedAssets'];

        $nfr = $s['inventory'] + $s['receivables'] - ($s['supplierDebts'] + $s['salaryDebts'] + $s['taxDebts']);
        $netCash = $workingCapitalLT - $nfr;

        return [
            'hasData' => true,
            'currentRatio' => $this->ratio($currentRatio, 1.5, 1.0),
            'quickRatio' => $this->ratio($quickRatio, 1.0, 0.7),
            'cashRatio' => $this->ratio($cashRatio, 0.2, 0.1),
            'workingCapital' => self::amount($workingCapital, $workingCapital > 0 ? 'normal' : 'critical'),
            'workingCapitalLongTerm' => self::amount($workingCapitalLT, $workingCapitalLT > 0 ? 'normal' : 'warning'),
            'workingCapitalRequirement' => self::amount($nfr),
            'netCash' => self::amount($netCash, $netCash > 0 ? 'normal' : 'warning'),
        ];
    }

    private function computeSolvencyRatios(array $s): array
    {
        if (!$s['hasData']) {
            return ['hasData' => false];
        }

        $equity = $s['equity'];
        $totalDebt = $s['totalLiabilities'];
        $totalAssets = $s['totalAssets'];

        $debtToEquity = $equity > 0 ? $totalDebt / $equity : null;
        $autonomy = $totalAssets > 0 ? ($equity / $totalAssets) * 100 : null;
        $generalSolvency = $totalDebt > 0 ? $totalAssets / $totalDebt : null;
        $debtRatio = $totalAssets > 0 ? ($totalDebt / $totalAssets) * 100 : null;

        $ebit = $s['netProfit'] + $s['profitTax'] + $s['interestExpense'];
        $interestCoverage = $s['interestExpense'] > 0 ? $ebit / $s['interestExpense'] : null;

        return [
            'hasData' => true,
            'debtToEquity' => $this->ratio($debtToEquity, 1.0, 2.0, inverse: true),
            'financialAutonomy' => $this->percent($autonomy, 33.0, 20.0),
            'debtRatio' => $this->percent($debtRatio, 67.0, 80.0, inverse: true),
            'generalSolvency' => $this->ratio($generalSolvency, 1.5, 1.0),
            'interestCoverage' => $s['interestExpense'] > 0
                ? $this->ratio($interestCoverage, 3.0, 1.5)
                : ['value' => null, 'status' => 'na'],
        ];
    }

    private function computeProfitabilityRatios(array $s): array
    {
        if (!$s['hasData']) {
            return ['hasData' => false];
        }

        $rev = $s['revenue'];
        $turnover = $s['turnover'] > 0 ? $s['turnover'] : $rev;
        $ebit = $s['netProfit'] + $s['profitTax'] + $s['interestExpense'];
        $ebitda = $ebit + $s['depreciation'];
        $cogsAndServices = $s['cogs'] + $s['servicesExpense'];

        $grossMargin = $turnover > 0 ? (($turnover - $cogsAndServices) / $turnover) * 100 : null;
        $operatingMargin = $turnover > 0 ? ($ebit / $turnover) * 100 : null;
        $ebitdaMargin = $turnover > 0 ? ($ebitda / $turnover) * 100 : null;
        $netMargin = $rev > 0 ? ($s['netProfit'] / $rev) * 100 : null;

        $roa = $s['totalAssets'] > 0 ? ($s['netProfit'] / $s['totalAssets']) * 100 : null;
        $roe = $s['equity'] > 0 ? ($s['netProfit'] / $s['equity']) * 100 : null;
        $capitalEmployed = $s['equity'] + $s['longTermDebt'];
        $roce = $capitalEmployed > 0 ? ($ebit / $capitalEmployed) * 100 : null;

        return [
            'hasData' => true,
            'grossMargin' => $this->percent($grossMargin, 25.0, 10.0),
            'operatingMargin' => $this->percent($operatingMargin, 10.0, 3.0),
            'ebitdaMargin' => $this->percent($ebitdaMargin, 15.0, 5.0),
            'netMargin' => $this->percent($netMargin, 5.0, 1.0),
            'returnOnAssets' => $this->percent($roa, 5.0, 1.0),
            'returnOnEquity' => $this->percent($roe, 10.0, 5.0),
            'returnOnCapitalEmployed' => $this->percent($roce, 10.0, 5.0),
            'ebit' => self::money($ebit),
            'ebitda' => self::money($ebitda),
        ];
    }

    private function computeEfficiencyRatios(array $s, ?int $latestMonth): array
    {
        if (!$s['hasData'] || $latestMonth === null) {
            return ['hasData' => false];
        }

        $annualisation = $latestMonth > 0 ? 12.0 / $latestMonth : 1.0;
        $annualisedRevenue = $s['revenue'] * $annualisation;
        $annualisedCogs = $s['cogs'] * $annualisation;
        $annualisedPurchases = ($s['cogs'] + $s['servicesExpense']) * $annualisation;

        $assetTurnover = $s['totalAssets'] > 0 ? $annualisedRevenue / $s['totalAssets'] : null;
        $fixedAssetTurnover = $s['fixedAssets'] > 0 ? $annualisedRevenue / $s['fixedAssets'] : null;
        $inventoryTurnover = $s['inventory'] > 0 && $annualisedCogs > 0
            ? $annualisedCogs / $s['inventory'] : null;
        $inventoryDays = $inventoryTurnover ? 365.0 / $inventoryTurnover : null;

        $dsoBalance = $annualisedRevenue > 0 ? ($s['receivables'] / $annualisedRevenue) * 365.0 : null;
        $dpoBalance = $annualisedPurchases > 0 ? ($s['supplierDebts'] / $annualisedPurchases) * 365.0 : null;
        $ccc = ($dsoBalance !== null && $dpoBalance !== null && $inventoryDays !== null)
            ? $dsoBalance + $inventoryDays - $dpoBalance
            : null;

        return [
            'hasData' => true,
            'assetTurnover' => $this->ratio($assetTurnover, 1.0, 0.5),
            'fixedAssetTurnover' => $this->ratio($fixedAssetTurnover, 2.0, 1.0),
            'inventoryTurnover' => $this->ratio($inventoryTurnover, 6.0, 3.0),
            'inventoryDays' => $this->days($inventoryDays, 60.0, 120.0, inverse: true),
            'dso' => $this->days($dsoBalance, 60.0, 90.0, inverse: true),
            'dpo' => $this->days($dpoBalance, 60.0, 30.0),
            'cashConversionCycle' => $this->days($ccc, 60.0, 120.0, inverse: true),
        ];
    }

    private function computeFiscalIndicators(Company $company, array $s): array
    {
        if (!$s['hasData']) {
            return ['hasData' => false];
        }

        $eurRate = $this->exchangeRateService->getRate('EUR') ?? 5.0;
        $revenueEur = $eurRate > 0 ? $s['revenue'] / $eurRate : 0.0;
        $microPlafonRon = self::MICRO_THRESHOLD_EUR * $eurRate;
        $microUsage = $microPlafonRon > 0 ? ($s['revenue'] / $microPlafonRon) * 100 : 0.0;

        $vatPlafon = self::VAT_THRESHOLD_RON;
        $vatUsage = ($s['revenue'] / $vatPlafon) * 100;

        return [
            'hasData' => true,
            'vatPayable' => self::amount($s['vatPayable'], $s['vatPayable'] > 0 ? 'warning' : 'normal'),
            'salaryDebts' => self::amount($s['salaryDebts'], $s['salaryDebts'] > 0 ? 'warning' : 'normal'),
            'stateTaxDebts' => self::amount($s['taxDebts'], $s['taxDebts'] > 0 ? 'warning' : 'normal'),
            'microThreshold' => [
                'isMicro' => $s['isMicro'],
                'plafonEur' => self::MICRO_THRESHOLD_EUR,
                'plafonRon' => self::money($microPlafonRon),
                'revenueEur' => self::money($revenueEur),
                'usagePercent' => round($microUsage, 1),
                'status' => self::thresholdStatus($microUsage),
            ],
            'vatThreshold' => [
                'isVatPayer' => $company->isVatPayer(),
                'plafonRon' => self::money($vatPlafon),
                'usagePercent' => round($vatUsage, 1),
                'status' => $company->isVatPayer() ? 'na' : self::thresholdStatus($vatUsage),
            ],
        ];
    }

    private function computeCashflowIndicators(array $s, array $monthlyEvolution, ?int $latestMonth): array
    {
        if (!$s['hasData']) {
            return ['hasData' => false];
        }

        $months = max(1, $latestMonth ?? count($monthlyEvolution));
        $monthlyExpenses = $s['expenses'] / $months;
        $cashAvailable = $s['cash'] + $s['bank'] + $s['securities'];
        $runway = $monthlyExpenses > 0 ? $cashAvailable / $monthlyExpenses : null;

        $burn = null;
        if (count($monthlyEvolution) >= 3) {
            $last3 = array_slice($monthlyEvolution, -3);
            $sum = array_sum(array_map(fn ($r) => (float) $r['profit'], $last3));
            $burn = $sum / count($last3);
        }

        $contributionRate = $s['revenue'] > 0 ? 1 - ($s['cogs'] + $s['servicesExpense']) / $s['revenue'] : 0;
        $fixedCosts = $s['salaries'] + $s['depreciation'] + $s['interestExpense'];
        $breakEven = $contributionRate > 0 ? $fixedCosts / $contributionRate : null;
        $breakEvenMonths = ($breakEven !== null && $monthlyExpenses > 0) ? $breakEven / max(1, $s['revenue'] / $months) : null;

        $operatingLeverage = null;
        if ($s['netProfit'] !== 0.0) {
            $contributionMargin = $s['revenue'] - $s['cogs'] - $s['servicesExpense'];
            $operatingLeverage = $contributionMargin / $s['netProfit'];
        }

        return [
            'hasData' => true,
            'cashRunwayMonths' => $this->ratio($runway, self::SAFE_RUNWAY_MONTHS, 2.0),
            'monthlyBurnRate' => self::amount($burn),
            'breakEvenRevenue' => $breakEven !== null ? self::amount($breakEven, $breakEven < $s['revenue'] ? 'normal' : 'warning') : ['value' => null, 'status' => 'na'],
            'breakEvenMonths' => $breakEvenMonths !== null ? ['value' => round($breakEvenMonths, 1), 'status' => $breakEvenMonths < 12 ? 'normal' : 'warning'] : ['value' => null, 'status' => 'na'],
            'contributionRatePercent' => round($contributionRate * 100, 1),
            'operatingLeverage' => $operatingLeverage !== null ? ['value' => round($operatingLeverage, 2), 'status' => 'normal'] : ['value' => null, 'status' => 'na'],
        ];
    }

    private function computeReceivablesAging(Connection $conn, string $companyId): array
    {
        $rows = $conn->fetchAllAssociative(
            "SELECT
                SUM(CASE WHEN DATEDIFF(CURRENT_DATE, issue_date) BETWEEN 0 AND 30 THEN total ELSE 0 END) AS bucket_0_30,
                SUM(CASE WHEN DATEDIFF(CURRENT_DATE, issue_date) BETWEEN 31 AND 60 THEN total ELSE 0 END) AS bucket_31_60,
                SUM(CASE WHEN DATEDIFF(CURRENT_DATE, issue_date) BETWEEN 61 AND 90 THEN total ELSE 0 END) AS bucket_61_90,
                SUM(CASE WHEN DATEDIFF(CURRENT_DATE, issue_date) > 90 THEN total ELSE 0 END) AS bucket_over_90,
                COUNT(CASE WHEN DATEDIFF(CURRENT_DATE, issue_date) > 90 THEN 1 END) AS count_over_90,
                COUNT(*) AS total_count,
                SUM(total) AS total_amount
             FROM invoice
             WHERE company_id = :companyId
               AND direction = 'outgoing'
               AND status NOT IN ('paid', 'cancelled', 'draft')
               AND deleted_at IS NULL
               AND paid_at IS NULL",
            ['companyId' => $companyId]
        );

        $r = $rows[0] ?? [];
        $total = (float) ($r['total_amount'] ?? 0);
        $over90 = (float) ($r['bucket_over_90'] ?? 0);
        $pctOver90 = $total > 0 ? ($over90 / $total) * 100 : 0;
        $countOver90 = (int) ($r['count_over_90'] ?? 0);
        $totalCount = (int) ($r['total_count'] ?? 0);

        // IFRS 9 simplified provision: 0% (0-30) + 1% (31-60) + 5% (61-90) + 25% (90+)
        $provision = ((float) ($r['bucket_31_60'] ?? 0)) * 0.01
            + ((float) ($r['bucket_61_90'] ?? 0)) * 0.05
            + $over90 * 0.25;

        return [
            'buckets' => [
                ['range' => '0-30',  'amount' => self::money((float) ($r['bucket_0_30'] ?? 0))],
                ['range' => '31-60', 'amount' => self::money((float) ($r['bucket_31_60'] ?? 0))],
                ['range' => '61-90', 'amount' => self::money((float) ($r['bucket_61_90'] ?? 0))],
                ['range' => '90+',   'amount' => self::money($over90)],
            ],
            'totalUnpaid' => self::money($total),
            'totalCount' => $totalCount,
            'countOver90' => $countOver90,
            'percentOver90' => round($pctOver90, 1),
            'overdueStatus' => $pctOver90 > 25 ? 'critical' : ($pctOver90 > 10 ? 'warning' : 'normal'),
            'estimatedProvision' => self::money($provision),
        ];
    }

    private function computeClientConcentration(Connection $conn, string $companyId, int $year): array
    {
        $rows = $conn->fetchAllAssociative(
            "SELECT receiver_name AS client_name, SUM(total) AS revenue
             FROM invoice
             WHERE company_id = :companyId
               AND direction = 'outgoing'
               AND status != 'cancelled'
               AND deleted_at IS NULL
               AND YEAR(issue_date) = :year
             GROUP BY client_id, receiver_name
             ORDER BY revenue DESC
             LIMIT 10",
            ['companyId' => $companyId, 'year' => $year]
        );

        $totalRevenue = $conn->fetchOne(
            "SELECT COALESCE(SUM(total), 0)
             FROM invoice
             WHERE company_id = :companyId
               AND direction = 'outgoing'
               AND status != 'cancelled'
               AND deleted_at IS NULL
               AND YEAR(issue_date) = :year",
            ['companyId' => $companyId, 'year' => $year]
        );

        $total = (float) $totalRevenue;
        $top5Sum = 0.0;
        $top10Sum = 0.0;
        $topClients = [];
        foreach ($rows as $idx => $r) {
            $amount = (float) $r['revenue'];
            if ($idx < 5) {
                $top5Sum += $amount;
            }
            $top10Sum += $amount;
            $topClients[] = [
                'name' => $r['client_name'] ?: '—',
                'revenue' => self::money($amount),
                'percent' => $total > 0 ? round(($amount / $total) * 100, 1) : 0,
            ];
        }

        $top5Share = $total > 0 ? ($top5Sum / $total) * 100 : 0;
        $top10Share = $total > 0 ? ($top10Sum / $total) * 100 : 0;

        return [
            'top5SharePercent' => round($top5Share, 1),
            'top10SharePercent' => round($top10Share, 1),
            'top5Status' => $top5Share > 70 ? 'critical' : ($top5Share > 50 ? 'warning' : 'normal'),
            'topClients' => $topClients,
            'totalRevenue' => self::money($total),
        ];
    }

    // ─── Shared helpers ─────────────────────────────────────────────────

    private function ratio(?float $value, float $green, float $red, bool $inverse = false): array
    {
        if ($value === null) {
            return ['value' => null, 'status' => 'na'];
        }
        $status = $inverse
            ? ($value <= $green ? 'normal' : ($value <= $red ? 'warning' : 'critical'))
            : ($value >= $green ? 'normal' : ($value >= $red ? 'warning' : 'critical'));
        return ['value' => round($value, 2), 'status' => $status];
    }

    private function percent(?float $value, float $green, float $red, bool $inverse = false): array
    {
        if ($value === null) {
            return ['value' => null, 'status' => 'na'];
        }
        $status = $inverse
            ? ($value <= $green ? 'normal' : ($value <= $red ? 'warning' : 'critical'))
            : ($value >= $green ? 'normal' : ($value >= $red ? 'warning' : 'critical'));
        return ['value' => round($value, 1), 'status' => $status];
    }

    private function days(?float $value, float $green, float $red, bool $inverse = false): array
    {
        if ($value === null) {
            return ['value' => null, 'status' => 'na'];
        }
        $status = $inverse
            ? ($value <= $green ? 'normal' : ($value <= $red ? 'warning' : 'critical'))
            : ($value >= $green ? 'normal' : ($value >= $red ? 'warning' : 'critical'));
        return ['value' => round($value, 0), 'status' => $status];
    }

    private static function thresholdStatus(float $usagePercent): string
    {
        if ($usagePercent >= 90) return 'critical';
        if ($usagePercent >= 70) return 'warning';
        return 'normal';
    }

    private static function amount(?float $value, string $status = 'normal'): array
    {
        if ($value === null) {
            return ['value' => null, 'status' => 'na'];
        }
        return ['value' => self::money($value), 'status' => $status];
    }

    private static function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
