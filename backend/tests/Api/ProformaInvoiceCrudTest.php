<?php

namespace App\Tests\Api;

class ProformaInvoiceCrudTest extends ApiTestCase
{
    private function createDocumentSeries(string $companyId): array
    {
        $prefix = 'PRO' . substr(md5(uniqid()), 0, 3);
        $series = $this->apiPost('/api/v1/document-series', [
            'prefix' => $prefix,
            'type' => 'proforma',
            'currentNumber' => 0,
        ], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(201);

        return $series;
    }

    private function createDraftProforma(string $companyId, ?string $seriesId = null): array
    {
        $body = [
            'issueDate' => '2026-02-15',
            'dueDate' => '2026-03-15',
            'validUntil' => '2026-03-01',
            'currency' => 'RON',
            'notes' => 'Test proforma',
            'lines' => [
                [
                    'description' => 'Servicii consultanta',
                    'quantity' => '10.00',
                    'unitOfMeasure' => 'ore',
                    'unitPrice' => '150.00',
                    'vatRate' => '19.00',
                    'vatCategoryCode' => 'S',
                    'discount' => '0.00',
                    'discountPercent' => '0.00',
                ],
            ],
        ];

        if ($seriesId) {
            $body['documentSeriesId'] = $seriesId;
        }

        $data = $this->apiPost('/api/v1/proforma-invoices', $body, ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(201);

        return $data;
    }

    public function testCreateProformaSuccess(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $data = $this->createDraftProforma($companyId);

        $this->assertArrayHasKey('id', $data);
        $this->assertArrayHasKey('number', $data);
        $this->assertEquals('draft', $data['status']);
        $this->assertCount(1, $data['lines']);
        $this->assertEquals('Servicii consultanta', $data['lines'][0]['description']);

        // Verify totals are computed
        $this->assertEquals('1500.00', $data['subtotal']);
        $this->assertEquals('285.00', $data['vatTotal']);
        $this->assertEquals('1785.00', $data['total']);
    }

    public function testCreateProformaValidationFailure(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        // Missing lines
        $this->apiPost('/api/v1/proforma-invoices', [
            'issueDate' => '2026-02-15',
        ], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testCreateProformaLineValidation(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        // Line with missing description
        $this->apiPost('/api/v1/proforma-invoices', [
            'issueDate' => '2026-02-15',
            'lines' => [
                [
                    'quantity' => '1.00',
                    'unitPrice' => '100.00',
                ],
            ],
        ], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testListProformas(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $this->createDraftProforma($companyId);

        $result = $this->apiGet('/api/v1/proforma-invoices', ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertGreaterThanOrEqual(1, count($result['data']));
    }

    public function testShowProforma(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $created = $this->createDraftProforma($companyId);

        $data = $this->apiGet('/api/v1/proforma-invoices/' . $created['id'], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);
        $this->assertEquals($created['id'], $data['id']);
        $this->assertArrayHasKey('lines', $data);
    }

    public function testUpdateDraftProforma(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $created = $this->createDraftProforma($companyId);

        $updated = $this->apiPut('/api/v1/proforma-invoices/' . $created['id'], [
            'notes' => 'Updated notes',
            'currency' => 'EUR',
            'lines' => [
                [
                    'description' => 'Updated line',
                    'quantity' => '5.00',
                    'unitOfMeasure' => 'buc',
                    'unitPrice' => '200.00',
                    'vatRate' => '19.00',
                    'vatCategoryCode' => 'S',
                    'discount' => '0.00',
                    'discountPercent' => '0.00',
                ],
            ],
        ], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertEquals('Updated notes', $updated['notes']);
        $this->assertEquals('EUR', $updated['currency']);
        $this->assertCount(1, $updated['lines']);
        $this->assertEquals('Updated line', $updated['lines'][0]['description']);
        // Verify recalculated totals
        $this->assertEquals('1000.00', $updated['subtotal']);
        $this->assertEquals('190.00', $updated['vatTotal']);
        $this->assertEquals('1190.00', $updated['total']);
    }

    public function testDeleteDraftProforma(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $created = $this->createDraftProforma($companyId);

        $this->apiDelete('/api/v1/proforma-invoices/' . $created['id'], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(204);

        // Verify it's gone (soft-deleted)
        $this->apiGet('/api/v1/proforma-invoices/' . $created['id'], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testSendProforma(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $created = $this->createDraftProforma($companyId);

        $result = $this->apiPost('/api/v1/proforma-invoices/' . $created['id'] . '/send', [], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);
        $this->assertEquals('sent', $result['status']);
        $this->assertNotNull($result['sentAt']);
    }

    public function testAcceptProforma(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $created = $this->createDraftProforma($companyId);

        // Send first
        $this->apiPost('/api/v1/proforma-invoices/' . $created['id'] . '/send', [], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);

        // Accept
        $result = $this->apiPost('/api/v1/proforma-invoices/' . $created['id'] . '/accept', [], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);
        $this->assertEquals('accepted', $result['status']);
        $this->assertNotNull($result['acceptedAt']);
    }

    public function testRejectProforma(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $created = $this->createDraftProforma($companyId);

        // Send first
        $this->apiPost('/api/v1/proforma-invoices/' . $created['id'] . '/send', [], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);

        // Reject
        $result = $this->apiPost('/api/v1/proforma-invoices/' . $created['id'] . '/reject', [], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);
        $this->assertEquals('rejected', $result['status']);
        $this->assertNotNull($result['rejectedAt']);
    }

    public function testCancelProforma(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $created = $this->createDraftProforma($companyId);

        $result = $this->apiPost('/api/v1/proforma-invoices/' . $created['id'] . '/cancel', [], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);
        $this->assertEquals('cancelled', $result['status']);
        $this->assertNotNull($result['cancelledAt']);
    }

    public function testConvertToInvoice(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $created = $this->createDraftProforma($companyId);

        // Send and accept
        $this->apiPost('/api/v1/proforma-invoices/' . $created['id'] . '/send', [], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);
        $this->apiPost('/api/v1/proforma-invoices/' . $created['id'] . '/accept', [], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);

        // Convert to invoice
        $invoice = $this->apiPost('/api/v1/proforma-invoices/' . $created['id'] . '/convert', [], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(201);
        $this->assertArrayHasKey('id', $invoice);
        $this->assertEquals('draft', $invoice['status']);
        $this->assertCount(1, $invoice['lines']);

        // Verify proforma is now converted
        $proforma = $this->apiGet('/api/v1/proforma-invoices/' . $created['id'], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);
        $this->assertEquals('converted', $proforma['status']);
        $this->assertNotNull($proforma['convertedInvoice']);
    }

    public function testUpdateNonDraftRejected(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $created = $this->createDraftProforma($companyId);

        // Send to make it non-draft
        $this->apiPost('/api/v1/proforma-invoices/' . $created['id'] . '/send', [], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);

        // Try to update the sent proforma
        $this->apiPut('/api/v1/proforma-invoices/' . $created['id'], [
            'notes' => 'Should fail',
        ], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testDeleteNonDeletableRejected(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $created = $this->createDraftProforma($companyId);

        // Send to make it non-deletable
        $this->apiPost('/api/v1/proforma-invoices/' . $created['id'] . '/send', [], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);

        $this->apiDelete('/api/v1/proforma-invoices/' . $created['id'], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(422);
    }

    public function testAutoNumberingSequence(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $series = $this->createDocumentSeries($companyId);

        // Create two proformas with the same series
        $pro1 = $this->createDraftProforma($companyId, $series['id']);
        $pro2 = $this->createDraftProforma($companyId, $series['id']);

        // Verify sequential numbering
        $this->assertStringStartsWith($series['prefix'] . '-', $pro1['number']);
        $this->assertStringStartsWith($series['prefix'] . '-', $pro2['number']);
        $this->assertNotEquals($pro1['number'], $pro2['number']);

        // Extract numbers and verify sequential
        $num1 = (int) substr($pro1['number'], strlen($series['prefix']) + 1);
        $num2 = (int) substr($pro2['number'], strlen($series['prefix']) + 1);
        $this->assertEquals($num1 + 1, $num2);
    }
}
