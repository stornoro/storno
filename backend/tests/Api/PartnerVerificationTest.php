<?php

namespace App\Tests\Api;

use App\Tests\Support\AnafRegistryStub;

/**
 * Partner verification endpoints and partner rules. ANAF is the scripted
 * AnafRegistryStub (config/services_test.yaml), so no request leaves the machine.
 */
class PartnerVerificationTest extends ApiTestCase
{
    private const CUI = '99999903';

    protected function setUp(): void
    {
        parent::setUp();
        AnafRegistryStub::reset();
    }

    protected function tearDown(): void
    {
        AnafRegistryStub::reset();
        parent::tearDown();
    }

    private function loginWithCompany(): string
    {
        $this->login();

        return $this->getFirstCompanyId();
    }

    private function createIndividual(string $companyId, array $extra = []): array
    {
        $created = $this->apiPost('/api/v1/clients', $extra + [
            'type' => 'individual',
            'name' => 'Client Reguli ' . uniqid(),
            'country' => 'RO',
            'county' => 'Bucuresti',
            'city' => 'Bucuresti',
            'address' => 'Str. Test 1',
        ], ['X-Company' => $companyId]);
        self::assertSame(201, $this->client->getResponse()->getStatusCode(), json_encode($created));

        return $created['client'];
    }

    private function createDraftInvoice(string $companyId, string $clientId, string $price = '1000.00'): string
    {
        $inv = $this->apiPost('/api/v1/invoices', [
            'documentType' => 'invoice',
            'issueDate' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
            'dueDate' => (new \DateTimeImmutable('+30 days'))->format('Y-m-d'),
            'currency' => 'RON',
            'clientId' => $clientId,
            'lines' => [
                ['description' => 'Servicii', 'quantity' => '1.00', 'unitOfMeasure' => 'buc', 'unitPrice' => $price, 'vatRate' => '0.00', 'vatCategoryCode' => 'Z'],
            ],
        ], ['X-Company' => $companyId]);
        $invoiceId = $inv['invoice']['id'] ?? null;
        self::assertNotNull($invoiceId, json_encode($inv));

        return $invoiceId;
    }

    public function testVerifyClientStoresTheAnafSnapshot(): void
    {
        $companyId = $this->loginWithCompany();
        AnafRegistryStub::found(self::CUI, [
            'stare_inactiv' => ['statusInactivi' => true, 'dataInactivare' => '2026-01-15'],
            'inregistrare_RTVAI' => ['statusTvaIncasare' => true, 'dataInceputTvaInc' => '2025-06-01'],
        ]);

        $created = $this->apiPost('/api/v1/clients', [
            'type' => 'company',
            'name' => 'Partener Test SRL',
            'cui' => self::CUI,
            'country' => 'RO',
            'registrationNumber' => 'J40/2/2020',
            'affiliated' => true,
            'status' => 'warning',
            'creditLimit' => 2500,
        ], ['X-Company' => $companyId]);
        self::assertContains($this->client->getResponse()->getStatusCode(), [200, 201], json_encode($created));
        $clientId = $created['client']['id'];
        self::assertTrue($created['client']['affiliated']);
        self::assertSame('warning', $created['client']['status']);
        self::assertSame('2500.00', $created['client']['creditLimit']);

        $verified = $this->apiPost('/api/v1/clients/' . $clientId . '/verify', [], ['X-Company' => $companyId]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), json_encode($verified));
        self::assertTrue($verified['result']['checked']);
        self::assertSame('anaf', $verified['result']['source']);
        self::assertTrue($verified['client']['inactive']);
        self::assertTrue($verified['client']['vatRegistered']);
        self::assertTrue($verified['client']['vatOnCollection']);
        self::assertSame('2025-06-01', substr($verified['client']['vatOnCollectionFrom'], 0, 10));
        self::assertTrue($verified['client']['efacturaRegistered']);
        self::assertNotNull($verified['client']['vatStatusCheckedAt']);
        self::assertStringContainsString('inactiv', $verified['client']['verificationNotes']);

        // the list carries the flags the badges need
        $list = $this->apiGet('/api/v1/clients?search=' . self::CUI, ['X-Company' => $companyId]);
        $row = array_values(array_filter($list['data'], fn ($r) => $r['id'] === $clientId))[0] ?? null;
        self::assertNotNull($row);
        self::assertTrue($row['inactive']);
        self::assertSame('warning', $row['status']);

        // a notification was created for the degraded partner
        $notifications = $this->apiGet('/api/v1/notifications', ['X-Company' => $companyId]);
        $types = array_column($notifications['data'] ?? $notifications['notifications'] ?? [], 'type');
        self::assertContains('partner.status_changed', $types);

        $this->apiDelete('/api/v1/clients/' . $clientId, ['X-Company' => $companyId]);
    }

    public function testVerifyIsNotApplicableForIndividuals(): void
    {
        $companyId = $this->loginWithCompany();
        $client = $this->createIndividual($companyId);

        $verified = $this->apiPost('/api/v1/clients/' . $client['id'] . '/verify', [], ['X-Company' => $companyId]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), json_encode($verified));
        self::assertFalse($verified['result']['checked']);
        self::assertSame('not_applicable', $verified['result']['error']);
    }

    public function testVerifySupplierAndVerifyAll(): void
    {
        $companyId = $this->loginWithCompany();
        AnafRegistryStub::found(self::CUI, ['inregistrare_scop_Tva' => ['scpTVA' => false]]);

        $created = $this->apiPost('/api/v1/suppliers', [
            'name' => 'Furnizor Test SRL',
            'cif' => self::CUI,
            'country' => 'RO',
            'county' => 'Bucuresti',
            'city' => 'Bucuresti',
            'address' => 'Str. Test 2',
            'registrationNumber' => 'J40/3/2020',
            'isVatPayer' => true,
            'affiliated' => true,
        ], ['X-Company' => $companyId]);
        self::assertContains($this->client->getResponse()->getStatusCode(), [200, 201], json_encode($created));
        $supplierId = $created['supplier']['id'];
        self::assertTrue($created['supplier']['affiliated']);

        $verified = $this->apiPost('/api/v1/suppliers/' . $supplierId . '/verify', [], ['X-Company' => $companyId]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), json_encode($verified));
        self::assertTrue($verified['result']['checked']);
        self::assertFalse($verified['supplier']['vatRegistered']);
        self::assertContains('lost_vat_registration', $verified['result']['changes']);

        // just checked → nothing stale with the default window
        $all = $this->apiPost('/api/v1/partners/verify-all', [], ['X-Company' => $companyId]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), json_encode($all));
        foreach (['checked', 'changed', 'failed', 'skipped'] as $key) {
            self::assertArrayHasKey($key, $all);
        }

        // days=0 → re-checks everybody, including the supplier just verified
        $all = $this->apiPost('/api/v1/partners/verify-all', ['days' => 0], ['X-Company' => $companyId]);
        self::assertGreaterThanOrEqual(1, $all['checked']);

        $this->apiDelete('/api/v1/suppliers/' . $supplierId, ['X-Company' => $companyId]);
    }

    public function testBlockedClientCannotBeInvoiced(): void
    {
        $companyId = $this->loginWithCompany();
        $client = $this->createIndividual($companyId);
        $invoiceId = $this->createDraftInvoice($companyId, $client['id']);

        $updated = $this->apiPatch('/api/v1/clients/' . $client['id'], ['status' => 'blocked'], ['X-Company' => $companyId]);
        self::assertSame('blocked', $updated['client']['status']);

        $issue = $this->apiPost('/api/v1/invoices/' . $invoiceId . '/issue', [], ['X-Company' => $companyId]);
        self::assertSame(422, $this->client->getResponse()->getStatusCode(), json_encode($issue));
        self::assertStringContainsString('blocat', $issue['error']);

        $this->apiPatch('/api/v1/clients/' . $client['id'], ['status' => 'active'], ['X-Company' => $companyId]);
        $issue = $this->apiPost('/api/v1/invoices/' . $invoiceId . '/issue', [], ['X-Company' => $companyId]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), json_encode($issue));
        self::assertNull($issue['warning']);

        $bad = $this->apiPatch('/api/v1/clients/' . $client['id'], ['status' => 'frozen'], ['X-Company' => $companyId]);
        self::assertSame(422, $this->client->getResponse()->getStatusCode(), json_encode($bad));
    }

    public function testCreditLimitWarnsButDoesNotRefuse(): void
    {
        $companyId = $this->loginWithCompany();
        $client = $this->createIndividual($companyId, ['creditLimit' => '1500']);
        self::assertSame('1500.00', $client['creditLimit']);

        // first invoice: 1000 of 1500 → no warning
        $first = $this->createDraftInvoice($companyId, $client['id'], '1000.00');
        $issue = $this->apiPost('/api/v1/invoices/' . $first . '/issue', [], ['X-Company' => $companyId]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), json_encode($issue));
        self::assertNull($issue['warning']);

        // second invoice: 1000 outstanding + 800 = 1800 > 1500 → warning, still issued
        $second = $this->createDraftInvoice($companyId, $client['id'], '800.00');
        $issue = $this->apiPost('/api/v1/invoices/' . $second . '/issue', [], ['X-Company' => $companyId]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), json_encode($issue));
        self::assertSame('issued', $issue['status']);
        self::assertSame('credit_limit_exceeded', $issue['warning']['code']);
        self::assertSame('1500.00', $issue['warning']['creditLimit']);
        self::assertSame('1000.00', $issue['warning']['outstanding']);
        self::assertSame('800.00', $issue['warning']['invoiceTotal']);
        self::assertSame('1800.00', $issue['warning']['projected']);

        // removing the limit removes the warning
        $this->apiPatch('/api/v1/clients/' . $client['id'], ['creditLimit' => null], ['X-Company' => $companyId]);
        $third = $this->createDraftInvoice($companyId, $client['id'], '5000.00');
        $issue = $this->apiPost('/api/v1/invoices/' . $third . '/issue', [], ['X-Company' => $companyId]);
        self::assertNull($issue['warning']);
    }
}
