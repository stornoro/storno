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

        // The export is a streamed response: its body is on the browser's internal response.
        $content = $this->client->getInternalResponse()->getContent();
        $this->assertStringContainsString('Numar', $content);

        // Which company comes first is not fixed, so the expected rows are read from the
        // invoice list of that company: the export has no page limit and leaves out only
        // cancelled invoices.
        $list = $this->apiGet('/api/v1/invoices?limit=20', ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);
        $numbers = [];
        foreach ($list['data'] as $invoice) {
            if ($invoice['status'] !== 'cancelled') {
                $numbers[] = $invoice['number'];
            }
        }
        $this->assertNotEmpty($numbers, 'The first company has no invoice to export.');

        $exported = [];
        foreach (array_slice(explode("\n", trim($content)), 1) as $line) {
            $exported[] = str_getcsv($line, ',', '"', '')[0];
        }
        foreach ($numbers as $number) {
            $this->assertContains($number, $exported);
        }
    }

    public function testExportCsvWithDateFilter(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $this->client->request('GET', '/api/v1/invoices/export/csv?dateFrom=2026-01-01&dateTo=2026-12-31', [], [], $this->buildHeaders(['X-Company' => $companyId]));

        $this->assertResponseStatusCodeSame(200);
    }

    public function testExportCsvAcceptsFromToAliases(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        // A range in the far past must come back empty: the aliases are applied, not ignored
        $this->client->request('GET', '/api/v1/invoices/export/csv?from=2000-01-01&to=2000-01-31', [], [], $this->buildHeaders(['X-Company' => $companyId]));

        $this->assertResponseStatusCodeSame(200);
        $this->assertStringNotContainsString('UEP2026000001', $this->client->getInternalResponse()->getContent());
    }

    public function testExportSagaXmlFiltersByDateRange(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $this->client->request('GET', '/api/v1/invoices/export/saga-xml?dateFrom=2000-01-01&dateTo=2000-01-31', [], [], $this->buildHeaders(['X-Company' => $companyId]));

        $this->assertResponseStatusCodeSame(200);
        $this->assertStringContainsString('application/xml', $this->client->getResponse()->headers->get('Content-Type'));
        $this->assertStringNotContainsString('UEP2026000001', $this->client->getInternalResponse()->getContent());
    }

    public function testReceiptsAndPaymentsSagaXmlAcceptDateRange(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        foreach (['receipts-saga-xml', 'payments-saga-xml'] as $endpoint) {
            $this->client->request('GET', "/api/v1/invoices/export/$endpoint?from=2000-01-01&to=2000-01-31", [], [], $this->buildHeaders(['X-Company' => $companyId]));
            $this->assertResponseStatusCodeSame(200);
            $this->assertStringContainsString('application/xml', $this->client->getResponse()->headers->get('Content-Type'));
            $this->assertStringNotContainsString('<Linie>', $this->client->getInternalResponse()->getContent(), "$endpoint: no document should fall in January 2000");
        }
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
