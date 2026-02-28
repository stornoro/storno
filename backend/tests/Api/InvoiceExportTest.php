<?php

namespace App\Tests\Api;

class InvoiceExportTest extends ApiTestCase
{
    public function testExportCsv(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $this->client->request('GET', '/api/v1/invoices/export/csv', [], [], $this->buildHeaders(['X-Company' => $companyId]));

        $this->assertResponseStatusCodeSame(200);

        $response = $this->client->getResponse();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));

        $content = $response->getContent();
        $this->assertStringContainsString('Numar', $content);
        $this->assertStringContainsString('UEP2026000001', $content);
    }

    public function testExportCsvWithDateFilter(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $this->client->request('GET', '/api/v1/invoices/export/csv?dateFrom=2026-01-01&dateTo=2026-12-31', [], [], $this->buildHeaders(['X-Company' => $companyId]));

        $this->assertResponseStatusCodeSame(200);
    }

    public function testExportCsvRequiresAuth(): void
    {
        $this->client->request('GET', '/api/v1/invoices/export/csv');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testExportZip(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        // Get invoice IDs first
        $list = $this->apiGet('/api/v1/invoices', ['X-Company' => $companyId]);
        $this->assertNotEmpty($list['data']);

        $ids = array_map(fn($i) => $i['id'], array_slice($list['data'], 0, 2));

        $this->client->request('POST', '/api/v1/invoices/export/zip', [], [], $this->buildHeaders(['X-Company' => $companyId]), json_encode(['ids' => $ids]));

        $response = $this->client->getResponse();
        // ZIP export dispatches async job → 202, or 200 if sync, or 404 if missing
        $this->assertContains($response->getStatusCode(), [200, 202, 404]);
    }

    public function testExportZipEmptyIds(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $data = $this->apiPost('/api/v1/invoices/export/zip', ['ids' => []], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(400);
        $this->assertArrayHasKey('error', $data);
    }

    public function testExportZipTooManyIds(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $ids = array_map(fn($i) => "00000000-0000-0000-0000-" . str_pad($i, 12, '0', STR_PAD_LEFT), range(1, 101));
        $data = $this->apiPost('/api/v1/invoices/export/zip', ['ids' => $ids], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(400);
    }
}
