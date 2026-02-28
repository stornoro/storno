<?php

namespace App\Tests\Api;

class EFacturaMessageTest extends ApiTestCase
{
    public function testListMessages(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $data = $this->apiGet('/api/v1/efactura-messages', ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertArrayHasKey('data', $data);
        $this->assertIsArray($data['data']);
        $this->assertArrayHasKey('total', $data);
        $this->assertArrayHasKey('page', $data);
    }

    public function testListMessagesWithFilter(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $data = $this->apiGet('/api/v1/efactura-messages?messageType=FACTURA PRIMITA', ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertArrayHasKey('data', $data);
    }

    public function testMessageNotFound(): void
    {
        $this->login();

        $this->apiGet('/api/v1/efactura-messages/00000000-0000-0000-0000-000000000000');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testListMessagesRequiresAuth(): void
    {
        $this->apiGet('/api/v1/efactura-messages');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testListMessagesRequiresCompany(): void
    {
        $this->login();

        $data = $this->apiGet('/api/v1/efactura-messages');

        $this->assertResponseStatusCodeSame(404);
    }
}
