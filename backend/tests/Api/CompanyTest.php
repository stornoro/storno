<?php

namespace App\Tests\Api;

class CompanyTest extends ApiTestCase
{
    public function testListCompanies(): void
    {
        $this->login();
        $data = $this->apiGet('/api/v1/companies');

        $this->assertResponseStatusCodeSame(200);
        $this->assertIsArray($data['data']);

        foreach ($data['data'] as $company) {
            $this->assertArrayHasKey('id', $company);
            $this->assertArrayHasKey('name', $company);
            $this->assertArrayHasKey('cif', $company);
            $this->assertArrayHasKey('syncEnabled', $company);
        }
    }

    public function testShowCompany(): void
    {
        $this->login();
        $id = $this->getFirstCompanyId();

        $data = $this->apiGet('/api/v1/companies/' . $id);

        $this->assertResponseStatusCodeSame(200);
        $this->assertArrayHasKey('name', $data);
        $this->assertArrayHasKey('cif', $data);
        $this->assertArrayHasKey('hasValidToken', $data);
    }

    public function testShowCompanyNotFound(): void
    {
        $this->login();
        $this->apiGet('/api/v1/companies/00000000-0000-0000-0000-000000000000');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testUpdateCompany(): void
    {
        $this->login();
        $id = $this->getFirstCompanyId();

        $data = $this->apiPatch('/api/v1/companies/' . $id, [
            'phone' => '0700000000',
        ]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertSame('0700000000', $data['phone']);
    }

    public function testToggleSync(): void
    {
        $this->login();
        $id = $this->getFirstCompanyId();

        $data = $this->apiPost('/api/v1/companies/' . $id . '/toggle-sync');

        $this->assertResponseStatusCodeSame(200);
        $this->assertArrayHasKey('syncEnabled', $data);
    }

    public function testListCompaniesUnauthenticated(): void
    {
        $this->apiGet('/api/v1/companies');

        $this->assertResponseStatusCodeSame(401);
    }
}
