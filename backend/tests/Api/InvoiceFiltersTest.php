<?php

namespace App\Tests\Api;

class InvoiceFiltersTest extends ApiTestCase
{
    public function testFilterByDuplicate(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $data = $this->apiGet('/api/v1/invoices?isDuplicate=true', ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertArrayHasKey('data', $data);
        $this->assertIsArray($data['data']);
    }

    public function testFilterByLateSubmission(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $data = $this->apiGet('/api/v1/invoices?isLateSubmission=true', ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertArrayHasKey('data', $data);
    }

    public function testInvoiceDetailHasNewFields(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $list = $this->apiGet('/api/v1/invoices', ['X-Company' => $companyId]);
        $this->assertNotEmpty($list['data']);

        $invoice = $list['data'][0];
        $this->assertArrayHasKey('isDuplicate', $invoice);
        $this->assertArrayHasKey('isLateSubmission', $invoice);
        $this->assertArrayHasKey('paidAt', $invoice);
        $this->assertArrayHasKey('paymentMethod', $invoice);
    }

    public function testInvoiceDetailHasExtendedFields(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $list = $this->apiGet('/api/v1/invoices', ['X-Company' => $companyId]);
        $this->assertNotEmpty($list['data']);

        $invoiceId = $list['data'][0]['id'];
        $detail = $this->apiGet('/api/v1/invoices/' . $invoiceId, ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertArrayHasKey('cancelledAt', $detail);
        $this->assertArrayHasKey('deliveryLocation', $detail);
        $this->assertArrayHasKey('projectReference', $detail);
        $this->assertArrayHasKey('attachments', $detail);
        $this->assertIsArray($detail['attachments']);
    }

    public function testFilterBySupplier(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        // Use a non-existent supplier ID to just verify the filter works without error
        $data = $this->apiGet('/api/v1/invoices?supplierId=00000000-0000-0000-0000-000000000000', ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertArrayHasKey('data', $data);
    }
}
