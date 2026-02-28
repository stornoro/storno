<?php

namespace App\Tests\Api;

class InvoiceTest extends ApiTestCase
{
    public function testListInvoices(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $data = $this->apiGet('/api/v1/invoices', ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertArrayHasKey('data', $data);
        $this->assertIsArray($data['data']);
        $this->assertArrayHasKey('total', $data);
        $this->assertArrayHasKey('page', $data);
        $this->assertArrayHasKey('limit', $data);
    }

    public function testListInvoicesRequiresCompany(): void
    {
        $this->login();

        $this->apiGet('/api/v1/invoices');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testShowInvoice(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $list = $this->apiGet('/api/v1/invoices', ['X-Company' => $companyId]);
        $this->assertNotEmpty($list['data'], 'No invoices found to test show endpoint');

        $invoiceId = $list['data'][0]['id'];
        $invoice = $this->apiGet('/api/v1/invoices/' . $invoiceId, ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertArrayHasKey('number', $invoice);
        $this->assertArrayHasKey('status', $invoice);
        $this->assertArrayHasKey('direction', $invoice);
        $this->assertArrayHasKey('total', $invoice);
        $this->assertArrayHasKey('lines', $invoice);
        $this->assertIsArray($invoice['lines']);
    }

    public function testInvoiceEvents(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $list = $this->apiGet('/api/v1/invoices', ['X-Company' => $companyId]);
        $this->assertNotEmpty($list['data'], 'No invoices found to test events endpoint');

        $invoiceId = $list['data'][0]['id'];
        $events = $this->apiGet('/api/v1/invoices/' . $invoiceId . '/events', ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertIsArray($events);

        foreach ($events as $event) {
            $this->assertArrayHasKey('newStatus', $event);
            $this->assertArrayHasKey('createdAt', $event);
        }
    }

    public function testInvoiceNotFound(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $this->apiGet('/api/v1/invoices/00000000-0000-0000-0000-000000000000', ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(404);
    }

    public function testListInvoicesUnauthenticated(): void
    {
        $this->apiGet('/api/v1/invoices');

        $this->assertResponseStatusCodeSame(401);
    }
}
