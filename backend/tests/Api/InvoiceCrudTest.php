<?php

namespace App\Tests\Api;

class InvoiceCrudTest extends ApiTestCase
{
    private function createDocumentSeries(string $companyId): array
    {
        $prefix = 'FAC' . substr(md5(uniqid()), 0, 3);
        $series = $this->apiPost('/api/v1/document-series', [
            'prefix' => $prefix,
            'type' => 'invoice',
            'currentNumber' => 0,
        ], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(201);

        return $series;
    }

    private function createDraftInvoice(string $companyId, ?string $seriesId = null, bool $withReceiver = false): array
    {
        $body = [
            'documentType' => 'invoice',
            'issueDate' => '2026-02-14',
            'dueDate' => '2026-03-14',
            'currency' => 'RON',
            'notes' => 'Test invoice',
            'lines' => [
                [
                    'description' => 'Servicii consultanta',
                    'quantity' => '10.00',
                    'unitOfMeasure' => 'ore',
                    'unitPrice' => '150.00',
                    'vatRate' => '21.00',
                    'vatCategoryCode' => 'S',
                    'discount' => '0.00',
                    'discountPercent' => '0.00',
                ],
            ],
        ];

        if ($seriesId) {
            $body['documentSeriesId'] = $seriesId;
        }
        if ($withReceiver) {
            $body['receiverName'] = 'Test Client SRL';
            $body['receiverCif'] = 'RO12345678';
        }

        $data = $this->apiPost('/api/v1/invoices', $body, ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(201);

        return $data;
    }

    public function testCreateInvoiceSuccess(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $data = $this->createDraftInvoice($companyId);

        $this->assertArrayHasKey('id', $data);
        $this->assertArrayHasKey('number', $data);
        $this->assertEquals('draft', $data['status']);
        $this->assertEquals('outgoing', $data['direction']);
        $this->assertEquals('invoice', $data['documentType']);
        $this->assertCount(1, $data['lines']);
        $this->assertEquals('Servicii consultanta', $data['lines'][0]['description']);

        // Verify totals are computed (10 × 150 = 1500, VAT 21% = 315, total = 1815)
        $this->assertEquals('1500.00', $data['subtotal']);
        $this->assertEquals('315.00', $data['vatTotal']);
        $this->assertEquals('1815.00', $data['total']);
    }

    public function testCreateInvoiceValidationFailure(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        // Missing lines
        $this->apiPost('/api/v1/invoices', [
            'documentType' => 'invoice',
            'issueDate' => '2026-02-14',
        ], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testCreateInvoiceLineValidation(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        // Line with missing description
        $this->apiPost('/api/v1/invoices', [
            'documentType' => 'invoice',
            'issueDate' => '2026-02-14',
            'lines' => [
                [
                    'quantity' => '1.00',
                    'unitPrice' => '100.00',
                ],
            ],
        ], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testUpdateDraftInvoice(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $created = $this->createDraftInvoice($companyId);

        $updated = $this->apiPut('/api/v1/invoices/' . $created['id'], [
            'notes' => 'Updated notes',
            'currency' => 'EUR',
            'lines' => [
                [
                    'description' => 'Updated line',
                    'quantity' => '5.00',
                    'unitOfMeasure' => 'buc',
                    'unitPrice' => '200.00',
                    'vatRate' => '21.00',
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
        // Verify recalculated totals (5 × 200 = 1000, VAT 21% = 210, total = 1210)
        $this->assertEquals('1000.00', $updated['subtotal']);
        $this->assertEquals('210.00', $updated['vatTotal']);
        $this->assertEquals('1210.00', $updated['total']);
    }

    public function testUpdateNonDraftRejected(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        // Create and issue to make it non-draft
        $series = $this->createDocumentSeries($companyId);
        $created = $this->createDraftInvoice($companyId, $series['id'], withReceiver: true);
        $this->apiPost('/api/v1/invoices/' . $created['id'] . '/issue', [], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);

        // Try to update the now-issued invoice
        $this->apiPut('/api/v1/invoices/' . $created['id'], [
            'notes' => 'Should fail',
        ], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testDeleteDraftInvoice(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $created = $this->createDraftInvoice($companyId);

        $this->apiDelete('/api/v1/invoices/' . $created['id'], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(204);

        // Verify it's gone (soft-deleted)
        $this->apiGet('/api/v1/invoices/' . $created['id'], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testDeleteNonDeletableRejected(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        // Create, issue → issued (not deletable)
        $series = $this->createDocumentSeries($companyId);
        $created = $this->createDraftInvoice($companyId, $series['id'], withReceiver: true);
        $this->apiPost('/api/v1/invoices/' . $created['id'] . '/issue', [], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);

        $this->apiDelete('/api/v1/invoices/' . $created['id'], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(422);
    }

    public function testIssueDraftInvoice(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $series = $this->createDocumentSeries($companyId);
        $created = $this->createDraftInvoice($companyId, $series['id'], withReceiver: true);
        $this->assertStringStartsWith('DRAFT-', $created['number']);

        $result = $this->apiPost('/api/v1/invoices/' . $created['id'] . '/issue', [], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertEquals('issued', $result['status']);
        $this->assertArrayHasKey('number', $result);
        $this->assertStringStartsWith($series['prefix'], $result['number']);
    }

    public function testIssueWithoutReceiverFails(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        // Create draft without receiver
        $series = $this->createDocumentSeries($companyId);
        $created = $this->createDraftInvoice($companyId, $series['id']);

        // Issue should fail validation (no receiver)
        $result = $this->apiPost('/api/v1/invoices/' . $created['id'] . '/issue', [], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertArrayHasKey('errors', $result);
        $this->assertFalse($result['valid']);
    }

    public function testSubmitIssuedInvoice(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $series = $this->createDocumentSeries($companyId);
        $created = $this->createDraftInvoice($companyId, $series['id'], withReceiver: true);

        // Issue first
        $this->apiPost('/api/v1/invoices/' . $created['id'] . '/issue', [], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);

        // Then submit to ANAF
        $result = $this->apiPost('/api/v1/invoices/' . $created['id'] . '/submit', [], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);
        $this->assertArrayHasKey('message', $result);
    }

    public function testCancelDraftInvoice(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $created = $this->createDraftInvoice($companyId);

        $result = $this->apiPost('/api/v1/invoices/' . $created['id'] . '/cancel', [
            'reason' => 'Test cancellation',
        ], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertEquals('cancelled', $result['status']);
        $this->assertNotNull($result['cancelledAt']);
        $this->assertEquals('Test cancellation', $result['cancellationReason']);
    }

    public function testAutoNumberingSequence(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $series = $this->createDocumentSeries($companyId);

        // Create two draft invoices with the same series
        $inv1 = $this->createDraftInvoice($companyId, $series['id'], withReceiver: true);
        $inv2 = $this->createDraftInvoice($companyId, $series['id'], withReceiver: true);

        // Drafts should have temporary numbers
        $this->assertStringStartsWith('DRAFT-', $inv1['number']);
        $this->assertStringStartsWith('DRAFT-', $inv2['number']);

        // Issue both — numbering happens at finalization
        $this->apiPost('/api/v1/invoices/' . $inv1['id'] . '/issue', [], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);
        $this->apiPost('/api/v1/invoices/' . $inv2['id'] . '/issue', [], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);

        // Fetch updated invoices
        $inv1 = $this->apiGet('/api/v1/invoices/' . $inv1['id'], ['X-Company' => $companyId]);
        $inv2 = $this->apiGet('/api/v1/invoices/' . $inv2['id'], ['X-Company' => $companyId]);

        // Verify sequential numbering from series
        $this->assertStringStartsWith($series['prefix'], $inv1['number']);
        $this->assertStringStartsWith($series['prefix'], $inv2['number']);
        $this->assertNotEquals($inv1['number'], $inv2['number']);

        // Extract numbers and verify sequential
        $num1 = (int) substr($inv1['number'], strlen($series['prefix']));
        $num2 = (int) substr($inv2['number'], strlen($series['prefix']));
        $this->assertEquals($num1 + 1, $num2);
    }
}
