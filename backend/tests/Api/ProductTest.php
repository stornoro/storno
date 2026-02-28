<?php

namespace App\Tests\Api;

class ProductTest extends ApiTestCase
{
    public function testListProducts(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $data = $this->apiGet('/api/v1/products', ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertIsArray($data['data']);
        $this->assertArrayHasKey('total', $data);
    }

    public function testShowProduct(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $list = $this->apiGet('/api/v1/products', ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);
        $this->assertNotEmpty($list['data'], 'No products found');

        $productId = $list['data'][0]['id'];
        $data = $this->apiGet('/api/v1/products/' . $productId, ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertArrayHasKey('name', $data);
        $this->assertArrayHasKey('unitOfMeasure', $data);
        $this->assertArrayHasKey('defaultPrice', $data);
    }

    public function testProductNotFound(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $this->apiGet('/api/v1/products/00000000-0000-0000-0000-000000000000', ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(404);
    }
}
