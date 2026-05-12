<?php

namespace App\Tests\Api;

class BalanceAnalysisTest extends ApiTestCase
{
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
