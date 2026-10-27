<?php

namespace App\Tests\Api;

use App\Entity\Company;
use App\Entity\TrialBalance;
use App\Entity\TrialBalanceRow;

class BalanceAnalysisTest extends ApiTestCase
{
    public function testAnalysisCarriesTheProfitTaxEstimateFromInvoices(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $data = $this->apiGet('/api/v1/balances/analysis?year=2026', ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertArrayHasKey('profitTaxEstimate', $data);
        $est = $data['profitTaxEstimate'];
        $this->assertSame(2026, $est['year']);
        $this->assertSame('RON', $est['currency']);
        $this->assertSame(16, $est['profitTaxRate']);
        $this->assertIsArray($est['quarters']);
        $this->assertNotEmpty($est['quarters']);
        foreach (['revenue', 'invoicedExpenses', 'grossProfit', 'estimatedProfitTax', 'microTax1', 'microTax3', 'invoiceCount'] as $key) {
            $this->assertArrayHasKey($key, $est['ytd']);
            $this->assertArrayHasKey($key, $est['quarters'][0]);
        }
        $this->assertArrayHasKey('closed', $est['quarters'][0]);
    }

    public function testAnalysisIncludesGroupedSections(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $data = $this->apiGet('/api/v1/balances/analysis?year=2026', ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertArrayHasKey('year', $data);
        $this->assertArrayHasKey('indicators', $data);
        $this->assertArrayHasKey('balanceSheet', $data);
        $this->assertArrayHasKey('liquidity', $data);
        $this->assertArrayHasKey('solvency', $data);
        $this->assertArrayHasKey('profitabilityRatios', $data);
        $this->assertArrayHasKey('efficiency', $data);
        $this->assertArrayHasKey('fiscal', $data);
        $this->assertArrayHasKey('cashflow', $data);
        $this->assertArrayHasKey('aging', $data);
        $this->assertArrayHasKey('concentration', $data);
    }

    public function testTrialBalanceSectionsReportNoDataWhenEmpty(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $data = $this->apiGet('/api/v1/balances/analysis?year=2026', ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertArrayHasKey('hasData', $data['balanceSheet']);
        $this->assertArrayHasKey('hasData', $data['liquidity']);
        $this->assertArrayHasKey('hasData', $data['solvency']);
        $this->assertArrayHasKey('hasData', $data['profitabilityRatios']);
        $this->assertArrayHasKey('hasData', $data['efficiency']);
        $this->assertArrayHasKey('hasData', $data['fiscal']);
        $this->assertArrayHasKey('hasData', $data['cashflow']);
    }

    public function testAgingHasFourBucketsAlways(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $data = $this->apiGet('/api/v1/balances/analysis?year=2026', ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertCount(4, $data['aging']['buckets']);

        $ranges = array_column($data['aging']['buckets'], 'range');
        $this->assertSame(['0-30', '31-60', '61-90', '90+'], $ranges);

        $this->assertArrayHasKey('totalUnpaid', $data['aging']);
        $this->assertArrayHasKey('percentOver90', $data['aging']);
        $this->assertArrayHasKey('estimatedProvision', $data['aging']);
        $this->assertArrayHasKey('overdueStatus', $data['aging']);
    }

    public function testConcentrationReturnsStructureForInvoices(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $data = $this->apiGet('/api/v1/balances/analysis?year=2026', ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertArrayHasKey('top5SharePercent', $data['concentration']);
        $this->assertArrayHasKey('top10SharePercent', $data['concentration']);
        $this->assertArrayHasKey('top5Status', $data['concentration']);
        $this->assertArrayHasKey('topClients', $data['concentration']);
        $this->assertArrayHasKey('totalRevenue', $data['concentration']);
        $this->assertIsArray($data['concentration']['topClients']);
    }

    public function testFiscalIncludesBothThresholds(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $data = $this->apiGet('/api/v1/balances/analysis?year=2026', ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(200);
        if ($data['fiscal']['hasData'] === true) {
            $this->assertArrayHasKey('microThreshold', $data['fiscal']);
            $this->assertArrayHasKey('vatThreshold', $data['fiscal']);
            $this->assertArrayHasKey('plafonEur', $data['fiscal']['microThreshold']);
            $this->assertEquals(250000, $data['fiscal']['microThreshold']['plafonEur']);
        } else {
            $this->assertSame(false, $data['fiscal']['hasData']);
        }
    }

    public function testYearIndicatorsUseCumulatedTotalsAndMonthlyEvolutionUsesTheMonth(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->removeTrialBalances($em, $companyId);
        $company = $em->getRepository(Company::class)->find($companyId);

        $balance = (new TrialBalance())
            ->setCompany($company)
            ->setYear(2026)
            ->setMonth(9)
            ->setOriginalFilename('balance.pdf')
            ->setStoragePath('test/balance.pdf')
            ->setStatus('completed')
            ->setTotalAccounts(3)
            ->setProcessedAt(new \DateTimeImmutable());
        $em->persist($balance);

        // [code, name, initD, initC, prevD, prevC, currD, currC, totD, totC, finD, finC]
        $rows = [
            // revenue: 100 000 so far this year, of which 30 000 in September
            ['704', 'VENITURI DIN SERVICII', '0', '0', '0', '70000', '0', '30000', '0', '100000', '0', '0'],
            // expenses: 40 000 so far this year, of which 10 000 in September
            ['628', 'ALTE CHELTUIELI', '0', '0', '30000', '30000', '10000', '10000', '40000', '40000', '0', '0'],
            ['5121', 'CONTURI LA BANCA', '50000', '0', '120000', '60000', '30000', '10000', '150000', '70000', '80000', '0'],
        ];
        foreach ($rows as [$code, $name, $iD, $iC, $pD, $pC, $cD, $cC, $tD, $tC, $fD, $fC]) {
            $em->persist((new TrialBalanceRow())
                ->setTrialBalance($balance)
                ->setAccountCode($code)->setAccountName($name)
                ->setInitialDebit($iD)->setInitialCredit($iC)
                ->setPreviousDebit($pD)->setPreviousCredit($pC)
                ->setCurrentDebit($cD)->setCurrentCredit($cC)
                ->setTotalDebit($tD)->setTotalCredit($tC)
                ->setFinalDebit($fD)->setFinalCredit($fC));
        }
        $em->flush();

        $data = $this->apiGet('/api/v1/balances/analysis?year=2026', ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);

        $this->assertSame('100000.00', $data['indicators']['revenue']);
        $this->assertSame('40000.00', $data['indicators']['expenses']);
        $this->assertSame('60000.00', $data['indicators']['netProfit']);
        $this->assertSame('80000.00', $data['indicators']['bankBalance']);
        $this->assertSame('100000.00', $data['yoyComparison']['current']['revenue']);
        $this->assertSame('40000.00', $data['topExpenses'][0]['amount']);

        $september = array_values(array_filter($data['monthlyEvolution'], fn ($m) => $m['month'] === 9));
        $this->assertCount(1, $september);
        $this->assertSame('30000.00', $september[0]['revenue']);
        $this->assertSame('10000.00', $september[0]['expenses']);

        $this->removeTrialBalances($em, $companyId);
    }

    private function removeTrialBalances(object $em, string $companyId): void
    {
        $conn = $em->getConnection();
        $conn->executeStatement(
            'DELETE r FROM trial_balance_row r INNER JOIN trial_balance tb ON r.trial_balance_id = tb.id WHERE tb.company_id = :c',
            ['c' => $companyId]
        );
        $conn->executeStatement('DELETE FROM trial_balance WHERE company_id = :c', ['c' => $companyId]);
        $em->clear();
    }

    public function testAnalysisRequiresAuth(): void
    {
        $this->apiGet('/api/v1/balances/analysis?year=2026');
        $this->assertResponseStatusCodeSame(401);
    }

    public function testAnalysisRejectsInvalidYear(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $this->apiGet('/api/v1/balances/analysis?year=1500', ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(400);
    }
}
