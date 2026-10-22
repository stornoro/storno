<?php

namespace App\Tests\Api;

class VatReportTest extends ApiTestCase
{
    public function testVatReport(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $data = $this->apiGet('/api/v1/reports/vat?year=2026&month=2', ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertArrayHasKey('period', $data);
        $this->assertEquals('2026-02', $data['period']);
        $this->assertArrayHasKey('outgoing', $data);
        $this->assertArrayHasKey('incoming', $data);
        $this->assertArrayHasKey('totals', $data);
        $this->assertArrayHasKey('netVat', $data);
        $this->assertArrayHasKey('invoiceCount', $data);
    }

    public function testVatReportQuarterYearAndRange(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $data = $this->apiGet('/api/v1/reports/vat?year=2026&quarter=3', ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);
        $this->assertEquals('2026-Q3', $data['period']);
        $this->assertEquals('2026-07-01', $data['from']);
        $this->assertEquals('2026-09-30', $data['to']);

        $data = $this->apiGet('/api/v1/reports/vat?year=2026&period=year', ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);
        $this->assertEquals('2026', $data['period']);
        $this->assertEquals('2026-12-31', $data['to']);

        $data = $this->apiGet('/api/v1/reports/vat?from=2026-03-15&to=2026-04-14&direction=outgoing&finalOnly=1&vatRate=21', ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);
        $this->assertEquals('2026-03-15..2026-04-14', $data['period']);
        $this->assertEquals('outgoing', $data['filters']['direction']);
        $this->assertTrue($data['filters']['finalOnly']);
        $this->assertEquals('21.00', $data['filters']['vatRate']);
        $this->assertArrayHasKey('currencies', $data);
    }

    public function testVatReportInvalidRangeAndDirection(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $this->apiGet('/api/v1/reports/vat?from=2026-04-01&to=2026-03-01', ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(400);

        $this->apiGet('/api/v1/reports/vat?year=2026&month=3&direction=sideways', ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(400);
    }

    public function testVatOssReport(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $data = $this->apiGet('/api/v1/reports/vat/oss?year=2026&quarter=3', ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertEquals('2026-Q3', $data['period']);
        $this->assertEquals('2026-07-01', $data['from']);
        $this->assertEquals('2026-09-30', $data['to']);
        $this->assertEquals('EUR', $data['currency']);
        $this->assertArrayHasKey('countries', $data);
        $this->assertArrayHasKey('exchange', $data);
        $this->assertArrayHasKey('totals', $data);
        $this->assertEquals('2026-10-31', $data['payment']['dueDate']);
        $this->assertStringStartsWith('RO/', $data['payment']['reference']);
        $this->assertStringEndsWith('/Q3.2026', $data['payment']['reference']);
    }

    public function testVatOssReportInvalidQuarter(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $this->apiGet('/api/v1/reports/vat/oss?year=2026&quarter=5', ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testVatReportDefaultsToCurrentMonth(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $data = $this->apiGet('/api/v1/reports/vat', ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertArrayHasKey('period', $data);
    }

    public function testVatReportInvalidMonth(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $data = $this->apiGet('/api/v1/reports/vat?month=13', ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testVatReportHasVatBreakdown(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $data = $this->apiGet('/api/v1/reports/vat?year=2026&month=1', ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(200);

        // Check VAT rate buckets exist
        $this->assertArrayHasKey('21.00', $data['outgoing']);
        $this->assertArrayHasKey('9.00', $data['outgoing']);
        $this->assertArrayHasKey('5.00', $data['outgoing']);
        $this->assertArrayHasKey('0.00', $data['outgoing']);

        // Check structure of each bucket
        $this->assertArrayHasKey('taxableBase', $data['outgoing']['21.00']);
        $this->assertArrayHasKey('vatAmount', $data['outgoing']['21.00']);
    }

    public function testVatReportRequiresAuth(): void
    {
        $this->apiGet('/api/v1/reports/vat');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testVatReportRequiresCompany(): void
    {
        $this->login();

        $data = $this->apiGet('/api/v1/reports/vat');

        $this->assertResponseStatusCodeSame(404);
    }
}
