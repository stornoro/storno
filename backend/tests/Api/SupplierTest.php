<?php

namespace App\Tests\Api;

class SupplierTest extends ApiTestCase
{
    public function testListSuppliers(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $data = $this->apiGet('/api/v1/suppliers', ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertArrayHasKey('data', $data);
        $this->assertIsArray($data['data']);
        $this->assertArrayHasKey('total', $data);
        $this->assertArrayHasKey('page', $data);
    }

    public function testListSuppliersWithSearch(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $data = $this->apiGet('/api/v1/suppliers?search=test', ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertArrayHasKey('data', $data);
    }

    public function testSupplierNotFound(): void
    {
        $this->login();

        $this->apiGet('/api/v1/suppliers/00000000-0000-0000-0000-000000000000');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testListSuppliersUnauthenticated(): void
    {
        $this->apiGet('/api/v1/suppliers');

        $this->assertResponseStatusCodeSame(401);
    }
}
