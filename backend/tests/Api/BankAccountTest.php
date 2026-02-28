<?php

namespace App\Tests\Api;

class BankAccountTest extends ApiTestCase
{
    public function testListBankAccounts(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $data = $this->apiGet('/api/v1/bank-accounts', ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertArrayHasKey('data', $data);
        $this->assertIsArray($data['data']);
    }

    public function testCreateBankAccount(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();
        $iban = 'RO49AAAA' . substr(md5(uniqid()), 0, 16);

        $data = $this->apiPost('/api/v1/bank-accounts', [
            'iban' => $iban,
            'bankName' => 'Banca Transilvania',
            'currency' => 'RON',
            'isDefault' => true,
        ], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(201);
        $this->assertEquals($iban, $data['iban']);
        $this->assertEquals('Banca Transilvania', $data['bankName']);
        $this->assertTrue($data['isDefault']);
    }

    public function testCreateBankAccountRequiresIban(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $data = $this->apiPost('/api/v1/bank-accounts', [
            'bankName' => 'Test Bank',
        ], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testCreateDuplicateIbanFails(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();
        $iban = 'RO49BBBB' . substr(md5(uniqid()), 0, 16);

        $this->apiPost('/api/v1/bank-accounts', ['iban' => $iban], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(201);

        $this->apiPost('/api/v1/bank-accounts', ['iban' => $iban], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(409);
    }

    public function testUpdateBankAccount(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();
        $iban = 'RO49CCCC' . substr(md5(uniqid()), 0, 16);

        $created = $this->apiPost('/api/v1/bank-accounts', [
            'iban' => $iban,
            'bankName' => 'BCR',
        ], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(201);

        $updated = $this->apiPatch('/api/v1/bank-accounts/' . $created['id'], [
            'bankName' => 'BRD',
            'isDefault' => true,
        ], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertEquals('BRD', $updated['bankName']);
        $this->assertTrue($updated['isDefault']);
    }

    public function testDeleteBankAccount(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();
        $iban = 'RO49DDDD' . substr(md5(uniqid()), 0, 16);

        $created = $this->apiPost('/api/v1/bank-accounts', [
            'iban' => $iban,
        ], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(201);

        $this->apiDelete('/api/v1/bank-accounts/' . $created['id'], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);
    }

    public function testListBankAccountsUnauthenticated(): void
    {
        $this->apiGet('/api/v1/bank-accounts');

        $this->assertResponseStatusCodeSame(401);
    }
}
