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

    public function testCreateIndividualClient(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $unique = uniqid('client-', true);
        $payload = [
            'type' => 'individual',
            'name' => 'Test Person ' . $unique,
            'address' => 'Strada Test 1',
            'city' => 'Bucuresti',
            'county' => 'Bucuresti',
            'country' => 'RO',
        ];

        $data = $this->apiPost('/api/v1/clients', $payload, ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(201);
        $this->assertArrayHasKey('client', $data);
        $this->assertArrayHasKey('id', $data['client']);
        $this->assertSame($payload['name'], $data['client']['name']);
        $this->assertSame('individual', $data['client']['type']);
        $this->assertSame('RO', $data['client']['country']);
    }

    public function testCreateCompanyClientWithoutCui(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $payload = [
            'type' => 'company',
            'name' => 'Foreign Co ' . uniqid(),
            'country' => 'DE',
            'city' => 'Berlin',
            'address' => 'Hauptstr 1',
        ];

        $data = $this->apiPost('/api/v1/clients', $payload, ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(201);
        $this->assertSame('DE', $data['client']['country']);
    }

    public function testCreateClientRequiresName(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $data = $this->apiPost('/api/v1/clients', [
            'type' => 'individual',
            'address' => 'X',
            'city' => 'Y',
            'county' => 'Bucuresti',
            'country' => 'RO',
        ], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertArrayHasKey('errors', $data);
    }

    public function testUpdateClient(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $created = $this->apiPost('/api/v1/clients', [
            'type' => 'individual',
            'name' => 'Update Target ' . uniqid(),
            'address' => 'Strada Test 2',
            'city' => 'Cluj',
            'county' => 'Cluj',
            'country' => 'RO',
        ], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(201);

        $clientId = $created['client']['id'];
        $newName = 'Renamed ' . uniqid();

        $updated = $this->apiPatch('/api/v1/clients/' . $clientId, [
            'name' => $newName,
        ], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertSame($newName, $updated['client']['name']);
    }

    public function testDeleteClient(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $created = $this->apiPost('/api/v1/clients', [
            'type' => 'individual',
            'name' => 'Delete Me ' . uniqid(),
            'address' => 'Strada Delete 1',
            'city' => 'Iasi',
            'county' => 'Iasi',
            'country' => 'RO',
        ], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(201);

        $clientId = $created['client']['id'];

        $this->apiDelete('/api/v1/clients/' . $clientId, ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(204);

        // After soft-delete, GET should 404
        $this->apiGet('/api/v1/clients/' . $clientId, ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(404);
    }
}
