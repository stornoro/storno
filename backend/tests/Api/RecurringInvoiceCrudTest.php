<?php

namespace App\Tests\Api;

class RecurringInvoiceCrudTest extends ApiTestCase
{
    private function createRecurringInvoice(string $companyId, array $overrides = []): array
    {
        $body = array_merge([
            'reference' => 'Chirie Hala C3',
            'documentType' => 'invoice',
            'currency' => 'RON',
            'frequency' => 'monthly',
            'frequencyDay' => 15,
            'nextIssuanceDate' => '2026-03-15',
            'notes' => 'Factura chirie [[luna]] [[an]]',
            'paymentTerms' => 'Net 30',
            'dueDateType' => 'days',
            'dueDateDays' => 30,
            'lines' => [
                [
                    'description' => 'Chirie hala [[luna]] [[an]]',
                    'quantity' => '1.00',
                    'unitOfMeasure' => 'buc',
                    'unitPrice' => '5000.00',
                    'vatRate' => '19.00',
                    'vatCategoryCode' => 'S',
                    'discount' => '0.00',
                    'discountPercent' => '0.00',
                ],
            ],
        ], $overrides);

        $data = $this->apiPost('/api/v1/recurring-invoices', $body, ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(201);

        return $data;
    }

    public function testCreateRecurringInvoiceSuccess(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $data = $this->createRecurringInvoice($companyId);

        $this->assertArrayHasKey('id', $data);
        $this->assertEquals('Chirie Hala C3', $data['reference']);
        $this->assertEquals('monthly', $data['frequency']);
        $this->assertEquals(15, $data['frequencyDay']);
        $this->assertTrue($data['isActive']);
        $this->assertCount(1, $data['lines']);
        $this->assertEquals('5000.00', $data['subtotal']);
        $this->assertEquals('950.00', $data['vatTotal']);
        $this->assertEquals('5950.00', $data['total']);
    }

    public function testListRecurringInvoices(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $this->createRecurringInvoice($companyId);
        $this->createRecurringInvoice($companyId, ['reference' => 'Hosting lunar']);

        $data = $this->apiGet('/api/v1/recurring-invoices', ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('total', $data);
        $this->assertGreaterThanOrEqual(2, $data['total']);
    }

    public function testShowRecurringInvoice(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $created = $this->createRecurringInvoice($companyId);

        $data = $this->apiGet('/api/v1/recurring-invoices/' . $created['id'], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);
        $this->assertEquals($created['id'], $data['id']);
        $this->assertArrayHasKey('lines', $data);
        $this->assertArrayHasKey('dueDateType', $data);
        $this->assertEquals('days', $data['dueDateType']);
        $this->assertEquals(30, $data['dueDateDays']);
    }

    public function testUpdateRecurringInvoice(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $created = $this->createRecurringInvoice($companyId);

        $updated = $this->apiPut('/api/v1/recurring-invoices/' . $created['id'], [
            'reference' => 'Chirie actualizata',
            'currency' => 'EUR',
            'lines' => [
                [
                    'description' => 'Chirie actualizata [[luna]]',
                    'quantity' => '1.00',
                    'unitOfMeasure' => 'buc',
                    'unitPrice' => '6000.00',
                    'vatRate' => '19.00',
                    'vatCategoryCode' => 'S',
                    'discount' => '0.00',
                    'discountPercent' => '0.00',
                ],
            ],
        ], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertEquals('Chirie actualizata', $updated['reference']);
        $this->assertEquals('EUR', $updated['currency']);
        $this->assertEquals('6000.00', $updated['subtotal']);
    }

    public function testDeleteRecurringInvoice(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $created = $this->createRecurringInvoice($companyId);

        $this->apiDelete('/api/v1/recurring-invoices/' . $created['id'], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(204);

        // Verify it's gone (soft-deleted)
        $this->apiGet('/api/v1/recurring-invoices/' . $created['id'], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testToggleRecurringInvoice(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $created = $this->createRecurringInvoice($companyId);
        $this->assertTrue($created['isActive']);

        // Toggle off
        $toggled = $this->apiPost('/api/v1/recurring-invoices/' . $created['id'] . '/toggle', [], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);
        $this->assertFalse($toggled['isActive']);

        // Toggle on
        $toggled2 = $this->apiPost('/api/v1/recurring-invoices/' . $toggled['id'] . '/toggle', [], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);
        $this->assertTrue($toggled2['isActive']);
    }

    public function testCreateValidationMissingLines(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $this->apiPost('/api/v1/recurring-invoices', [
            'frequency' => 'monthly',
            'frequencyDay' => 1,
            'nextIssuanceDate' => '2026-03-01',
        ], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testCreateValidationInvalidFrequency(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $this->apiPost('/api/v1/recurring-invoices', [
            'frequency' => 'invalid_frequency',
            'frequencyDay' => 1,
            'nextIssuanceDate' => '2026-03-01',
            'lines' => [
                ['description' => 'Test', 'quantity' => '1', 'unitPrice' => '100'],
            ],
        ], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testCreateWeeklyRecurringInvoice(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $data = $this->createRecurringInvoice($companyId, [
            'frequency' => 'weekly',
            'frequencyDay' => 1, // Monday
            'reference' => 'Weekly report',
        ]);

        $this->assertEquals('weekly', $data['frequency']);
        $this->assertEquals(1, $data['frequencyDay']);
    }

    public function testCreateYearlyRecurringInvoice(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $data = $this->createRecurringInvoice($companyId, [
            'frequency' => 'yearly',
            'frequencyDay' => 1,
            'frequencyMonth' => 1,
            'reference' => 'Annual fee',
        ]);

        $this->assertEquals('yearly', $data['frequency']);
        $this->assertEquals(1, $data['frequencyMonth']);
    }
}
