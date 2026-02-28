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
        $this->assertArrayHasKey('19.00', $data['outgoing']);
        $this->assertArrayHasKey('9.00', $data['outgoing']);
        $this->assertArrayHasKey('5.00', $data['outgoing']);
        $this->assertArrayHasKey('0.00', $data['outgoing']);

        // Check structure of each bucket
        $this->assertArrayHasKey('taxableBase', $data['outgoing']['19.00']);
        $this->assertArrayHasKey('vatAmount', $data['outgoing']['19.00']);
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
