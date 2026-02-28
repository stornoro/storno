<?php

namespace App\Tests\Api;

class DocumentSeriesTest extends ApiTestCase
{
    public function testListDocumentSeries(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $data = $this->apiGet('/api/v1/document-series', ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertArrayHasKey('data', $data);
        $this->assertIsArray($data['data']);
    }

    public function testCreateDocumentSeries(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();
        $prefix = 'T' . substr(md5(uniqid()), 0, 4);

        $data = $this->apiPost('/api/v1/document-series', [
            'prefix' => $prefix,
            'type' => 'invoice',
            'currentNumber' => 100,
        ], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(201);
        $this->assertEquals($prefix, $data['prefix']);
        $this->assertEquals(100, $data['currentNumber']);
        $this->assertTrue($data['active']);
    }

    public function testCreateDocumentSeriesRequiresPrefix(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $data = $this->apiPost('/api/v1/document-series', [
            'type' => 'invoice',
        ], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testCreateDuplicatePrefixFails(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();
        $prefix = 'D' . substr(md5(uniqid()), 0, 4);

        // Create first
        $this->apiPost('/api/v1/document-series', ['prefix' => $prefix], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(201);

        // Try duplicate
        $this->apiPost('/api/v1/document-series', ['prefix' => $prefix], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(409);
    }

    public function testUpdateDocumentSeries(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();
        $prefix = 'U' . substr(md5(uniqid()), 0, 4);

        // Create
        $created = $this->apiPost('/api/v1/document-series', ['prefix' => $prefix], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(201);

        // Update
        $updated = $this->apiPatch('/api/v1/document-series/' . $created['id'], [
            'currentNumber' => 200,
            'active' => false,
        ], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertEquals(200, $updated['currentNumber']);
        $this->assertFalse($updated['active']);
    }

    public function testDeleteDocumentSeries(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();
        $prefix = 'X' . substr(md5(uniqid()), 0, 4);

        $created = $this->apiPost('/api/v1/document-series', ['prefix' => $prefix], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(201);

        $this->apiDelete('/api/v1/document-series/' . $created['id'], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);
    }

    public function testListDocumentSeriesUnauthenticated(): void
    {
        $this->apiGet('/api/v1/document-series');

        $this->assertResponseStatusCodeSame(401);
    }
}
