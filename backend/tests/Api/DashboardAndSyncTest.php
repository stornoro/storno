<?php

namespace App\Tests\Api;

class DashboardAndSyncTest extends ApiTestCase
{
    public function testDashboardStats(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $data = $this->apiGet('/api/v1/dashboard/stats', ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertArrayHasKey('invoices', $data);
        $this->assertArrayHasKey('total', $data['invoices']);
        $this->assertArrayHasKey('incoming', $data['invoices']);
        $this->assertArrayHasKey('outgoing', $data['invoices']);
        $this->assertArrayHasKey('byStatus', $data);
        $this->assertArrayHasKey('amounts', $data);
        $this->assertArrayHasKey('clientCount', $data);
        $this->assertArrayHasKey('productCount', $data);
    }

    public function testDashboardRequiresCompany(): void
    {
        $this->login();

        $this->apiGet('/api/v1/dashboard/stats');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testSyncStatus(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $data = $this->apiGet('/api/v1/sync/status', ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertArrayHasKey('syncEnabled', $data);
        $this->assertArrayHasKey('hasValidToken', $data);
    }

    public function testSyncLog(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $data = $this->apiGet('/api/v1/sync/log', ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertArrayHasKey('entries', $data);
        $this->assertIsArray($data['entries']);
    }

    public function testAnafStatus(): void
    {
        $this->login();

        $data = $this->apiGet('/api/v1/anaf/status');

        $this->assertResponseStatusCodeSame(200);
        $this->assertArrayHasKey('connected', $data);
        $this->assertArrayHasKey('hasToken', $data);
    }
}
