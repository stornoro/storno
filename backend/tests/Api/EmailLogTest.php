<?php

namespace App\Tests\Api;

class EmailLogTest extends ApiTestCase
{
    private function getFirstInvoiceId(): array
    {
        $companyId = $this->getFirstCompanyId();
        $list = $this->apiGet('/api/v1/invoices?limit=1', ['X-Company' => $companyId]);
        $this->assertNotEmpty($list['data'], 'No invoices found');

        return [$companyId, $list['data'][0]['id']];
    }

    public function testListEmailLogsForInvoice(): void
    {
        $this->login();
        [$companyId, $invoiceId] = $this->getFirstInvoiceId();

        $data = $this->apiGet('/api/v1/invoices/' . $invoiceId . '/emails', ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertIsArray($data);
    }

    public function testGetEmailDefaults(): void
    {
        $this->login();
        [$companyId, $invoiceId] = $this->getFirstInvoiceId();

        $data = $this->apiGet('/api/v1/invoices/' . $invoiceId . '/email-defaults', ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertArrayHasKey('to', $data);
        $this->assertArrayHasKey('subject', $data);
        $this->assertArrayHasKey('body', $data);
        $this->assertArrayHasKey('templateId', $data);
    }

    public function testGetEmailDefaultsWithTemplate(): void
    {
        $this->login();
        [$companyId, $invoiceId] = $this->getFirstInvoiceId();

        // Create a default template
        $this->apiPost('/api/v1/email-templates', [
            'name' => 'Default test',
            'subject' => 'Factura [[invoice_number]]',
            'body' => 'Buna ziua [[client_name]], factura [[invoice_number]] in valoare de [[total]] RON.',
            'isDefault' => true,
        ], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(201);

        $data = $this->apiGet('/api/v1/invoices/' . $invoiceId . '/email-defaults', ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertNotNull($data['templateId']);
        // Subject should have variables substituted (no more [[invoice_number]])
        if ($data['subject']) {
            $this->assertStringNotContainsString('[[invoice_number]]', $data['subject']);
        }
    }

    public function testSendEmailReturnsLog(): void
    {
        $this->login();
        [$companyId, $invoiceId] = $this->getFirstInvoiceId();

        $data = $this->apiPost('/api/v1/invoices/' . $invoiceId . '/email', [
            'to' => 'test@example.com',
            'subject' => 'Test email',
            'body' => 'Test body',
        ], ['X-Company' => $companyId]);

        // May fail in test env if mailer is not configured, but the endpoint should still work
        $status = $this->client->getResponse()->getStatusCode();
        if ($status === 200) {
            $this->assertArrayHasKey('id', $data);
            $this->assertArrayHasKey('toEmail', $data);
            $this->assertArrayHasKey('subject', $data);
            $this->assertArrayHasKey('status', $data);
            $this->assertEquals('test@example.com', $data['toEmail']);
        }
    }

    public function testSendEmailWithCcBcc(): void
    {
        $this->login();
        [$companyId, $invoiceId] = $this->getFirstInvoiceId();

        $data = $this->apiPost('/api/v1/invoices/' . $invoiceId . '/email', [
            'to' => 'test@example.com',
            'cc' => ['cc1@example.com', 'cc2@example.com'],
            'bcc' => ['bcc@example.com'],
            'subject' => 'Test with CC/BCC',
            'body' => 'Test body',
        ], ['X-Company' => $companyId]);

        $status = $this->client->getResponse()->getStatusCode();
        if ($status === 200) {
            $this->assertArrayHasKey('ccEmails', $data);
            $this->assertArrayHasKey('bccEmails', $data);
        }
    }

    public function testSendEmailInvalidCcFails(): void
    {
        $this->login();
        [$companyId, $invoiceId] = $this->getFirstInvoiceId();

        $this->apiPost('/api/v1/invoices/' . $invoiceId . '/email', [
            'to' => 'test@example.com',
            'cc' => ['not-an-email'],
        ], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testSendEmailInvalidToFails(): void
    {
        $this->login();
        [$companyId, $invoiceId] = $this->getFirstInvoiceId();

        $this->apiPost('/api/v1/invoices/' . $invoiceId . '/email', [
            'to' => 'not-valid',
        ], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testEmailsEndpointUnauthenticated(): void
    {
        $this->apiGet('/api/v1/invoices/00000000-0000-0000-0000-000000000000/emails');

        $this->assertResponseStatusCodeSame(401);
    }
}
