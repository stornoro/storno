<?php

namespace App\Tests\Api;

class EmailTemplateTest extends ApiTestCase
{
    public function testListEmailTemplates(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $data = $this->apiGet('/api/v1/email-templates', ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertArrayHasKey('data', $data);
        $this->assertIsArray($data['data']);
    }

    public function testCreateEmailTemplate(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $data = $this->apiPost('/api/v1/email-templates', [
            'name' => 'Sablon factura',
            'subject' => 'Factura [[invoice_number]] - [[company_name]]',
            'body' => "Buna ziua [[client_name]],\n\nVa trimitem factura [[invoice_number]] in valoare de [[total]] RON.\n\nCu stima,\n[[company_name]]",
            'isDefault' => true,
        ], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(201);
        $this->assertEquals('Sablon factura', $data['name']);
        $this->assertTrue($data['isDefault']);
    }

    public function testCreateEmailTemplateRequiresName(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $this->apiPost('/api/v1/email-templates', [
            'subject' => 'Test',
            'body' => 'Test body',
        ], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testCreateEmailTemplateRequiresSubject(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $this->apiPost('/api/v1/email-templates', [
            'name' => 'Test',
            'body' => 'Test body',
        ], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testCreateEmailTemplateRequiresBody(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $this->apiPost('/api/v1/email-templates', [
            'name' => 'Test',
            'subject' => 'Test subject',
        ], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testUpdateEmailTemplate(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $created = $this->apiPost('/api/v1/email-templates', [
            'name' => 'Sablon initial',
            'subject' => 'Subiect initial',
            'body' => 'Corp initial',
        ], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(201);

        $updated = $this->apiPatch('/api/v1/email-templates/' . $created['id'], [
            'name' => 'Sablon actualizat',
            'isDefault' => true,
        ], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertEquals('Sablon actualizat', $updated['name']);
        $this->assertTrue($updated['isDefault']);
    }

    public function testDeleteEmailTemplate(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $created = $this->apiPost('/api/v1/email-templates', [
            'name' => 'De sters',
            'subject' => 'Subiect',
            'body' => 'Corp',
        ], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(201);

        $this->apiDelete('/api/v1/email-templates/' . $created['id'], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);
    }

    public function testDefaultToggle(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        // Create first default
        $first = $this->apiPost('/api/v1/email-templates', [
            'name' => 'Sablon A',
            'subject' => 'Subiect A',
            'body' => 'Corp A',
            'isDefault' => true,
        ], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(201);
        $this->assertTrue($first['isDefault']);

        // Create second default — should unset first
        $second = $this->apiPost('/api/v1/email-templates', [
            'name' => 'Sablon B',
            'subject' => 'Subiect B',
            'body' => 'Corp B',
            'isDefault' => true,
        ], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(201);
        $this->assertTrue($second['isDefault']);

        // Verify the list — only second should be default
        $list = $this->apiGet('/api/v1/email-templates', ['X-Company' => $companyId]);
        $defaults = array_filter($list['data'], fn($t) => $t['isDefault'] === true);
        $this->assertCount(1, $defaults);
    }

    public function testListEmailTemplatesUnauthenticated(): void
    {
        $this->apiGet('/api/v1/email-templates');

        $this->assertResponseStatusCodeSame(401);
    }
}
