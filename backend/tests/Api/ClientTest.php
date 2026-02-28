<?php

namespace App\Tests\Api;

class ClientTest extends ApiTestCase
{
    public function testListClients(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $data = $this->apiGet('/api/v1/clients', ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertIsArray($data['data']);
        $this->assertArrayHasKey('total', $data);
    }

    public function testShowClient(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $list = $this->apiGet('/api/v1/clients', ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);
        $this->assertNotEmpty($list['data'], 'No clients found');

        $clientId = $list['data'][0]['id'];
        $data = $this->apiGet('/api/v1/clients/' . $clientId, ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertArrayHasKey('client', $data);
        $this->assertArrayHasKey('invoiceHistory', $data);
    }

    public function testClientNotFound(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $this->apiGet('/api/v1/clients/00000000-0000-0000-0000-000000000000', ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(404);
    }

    public function testListClientsRequiresCompany(): void
    {
        $this->login();

        $this->apiGet('/api/v1/clients');

        $this->assertResponseStatusCodeSame(404);
    }
}
