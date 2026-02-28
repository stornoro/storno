<?php

namespace App\Tests\Api;

class DeliveryNoteCrudTest extends ApiTestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function createDeliveryNoteSeries(string $companyId): array
    {
        $prefix = 'AVZ' . substr(md5(uniqid('', true) . microtime(true) . random_int(0, PHP_INT_MAX)), 0, 8);
        $series = $this->apiPost('/api/v1/document-series', [
            'prefix' => $prefix,
            'type' => 'delivery_note',
            'currentNumber' => 0,
        ], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(201);

        return $series;
    }

    private function createInvoiceSeries(string $companyId): array
    {
        $prefix = 'FAC' . substr(md5(uniqid('', true) . microtime(true) . random_int(0, PHP_INT_MAX)), 0, 8);
        $series = $this->apiPost('/api/v1/document-series', [
            'prefix' => $prefix,
            'type' => 'invoice',
            'currentNumber' => 0,
        ], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(201);

        return $series;
    }

    private function createTestClient(string $companyId): array
    {
        $response = $this->apiPost('/api/v1/clients', [
            'name' => 'Test Client SRL ' . substr(md5(uniqid()), 0, 6),
            'type' => 'company',
            'cui' => 'RO' . rand(10000000, 99999999),
            'address' => 'Str. Testului nr. 1',
            'city' => 'Bucuresti',
            'county' => 'Ilfov',
            'country' => 'RO',
            'email' => 'client' . rand(100, 999) . '@test.com',
        ], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(201);

        // The client create endpoint returns {'client': {...}}
        return $response['client'];
    }

    private function createDraftDeliveryNote(string $companyId, ?string $seriesId = null, ?string $clientId = null): array
    {
        $body = [
            'issueDate' => '2026-02-14',
            'dueDate' => '2026-03-14',
            'currency' => 'RON',
            'notes' => 'Test aviz de insotire',
            'lines' => [
                [
                    'description' => 'Marfa test',
                    'quantity' => '10.00',
                    'unitOfMeasure' => 'buc',
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
        if ($clientId) {
            $body['clientId'] = $clientId;
        }

        $data = $this->apiPost('/api/v1/delivery-notes', $body, ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(201);

        return $data['deliveryNote'];
    }

    private function createProforma(string $companyId): array
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
                    'quantity' => '5.00',
                    'unitOfMeasure' => 'ore',
                    'unitPrice' => '200.00',
                    'vatRate' => '19.00',
                    'vatCategoryCode' => 'S',
                    'discount' => '0.00',
                    'discountPercent' => '0.00',
                ],
            ],
        ];

        $data = $this->apiPost('/api/v1/proforma-invoices', $body, ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(201);

        return $data;
    }

    // -------------------------------------------------------------------------
    // List & Show
    // -------------------------------------------------------------------------

    public function testListDeliveryNotes(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $data = $this->apiGet('/api/v1/delivery-notes', ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertArrayHasKey('data', $data);
        $this->assertIsArray($data['data']);
        $this->assertArrayHasKey('total', $data);
        $this->assertArrayHasKey('page', $data);
        $this->assertArrayHasKey('limit', $data);
    }

    public function testListDeliveryNotesRequiresCompany(): void
    {
        $this->login();

        $this->apiGet('/api/v1/delivery-notes');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testListDeliveryNotesUnauthenticated(): void
    {
        $this->apiGet('/api/v1/delivery-notes');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testShowDeliveryNote(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $created = $this->createDraftDeliveryNote($companyId);

        $data = $this->apiGet('/api/v1/delivery-notes/' . $created['id'], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertEquals($created['id'], $data['id']);
        $this->assertArrayHasKey('number', $data);
        $this->assertArrayHasKey('status', $data);
        $this->assertArrayHasKey('lines', $data);
        $this->assertIsArray($data['lines']);
    }

    public function testShowDeliveryNoteNotFound(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $this->apiGet('/api/v1/delivery-notes/00000000-0000-0000-0000-000000000000', ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(404);
    }

    // -------------------------------------------------------------------------
    // Create
    // -------------------------------------------------------------------------

    public function testCreateDeliveryNoteSuccess(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $data = $this->createDraftDeliveryNote($companyId);

        $this->assertArrayHasKey('id', $data);
        $this->assertArrayHasKey('number', $data);
        $this->assertEquals('draft', $data['status']);
        $this->assertEquals('RON', $data['currency']);
        $this->assertStringStartsWith('AVIZ-', $data['number']);
        $this->assertCount(1, $data['lines']);
        $this->assertEquals('Marfa test', $data['lines'][0]['description']);

        // Verify totals: 10 × 150 = 1500, VAT 19% = 285, total = 1785
        $this->assertEquals('1500.00', $data['subtotal']);
        $this->assertEquals('285.00', $data['vatTotal']);
        $this->assertEquals('1785.00', $data['total']);
    }

    public function testCreateDeliveryNoteWithExplicitSeries(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $series = $this->createDeliveryNoteSeries($companyId);
        $data = $this->createDraftDeliveryNote($companyId, $series['id']);

        $this->assertArrayHasKey('id', $data);
        $this->assertEquals('draft', $data['status']);
        // Draft delivery note still gets a temporary AVIZ- number, but the series should be assigned
        $this->assertNotNull($data['documentSeries']);
        $this->assertEquals($series['id'], $data['documentSeries']['id']);
    }

    public function testCreateDeliveryNoteValidationFailureMissingLines(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $this->apiPost('/api/v1/delivery-notes', [
            'issueDate' => '2026-02-14',
            'currency' => 'RON',
        ], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testCreateDeliveryNoteLineValidationMissingDescription(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $this->apiPost('/api/v1/delivery-notes', [
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

    public function testCreateDeliveryNoteLineValidationNonPositiveQuantity(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $this->apiPost('/api/v1/delivery-notes', [
            'issueDate' => '2026-02-14',
            'lines' => [
                [
                    'description' => 'Produs test',
                    'quantity' => '0',
                    'unitPrice' => '100.00',
                ],
            ],
        ], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testCreateDeliveryNoteWithClient(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $client = $this->createTestClient($companyId);
        $data = $this->createDraftDeliveryNote($companyId, null, $client['id']);

        $this->assertResponseStatusCodeSame(201);
        $this->assertNotNull($data['client']);
        $this->assertEquals($client['id'], $data['client']['id']);
        $this->assertEquals($client['name'], $data['client']['name']);
    }

    // -------------------------------------------------------------------------
    // Update
    // -------------------------------------------------------------------------

    public function testUpdateDraftDeliveryNote(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $created = $this->createDraftDeliveryNote($companyId);

        $response = $this->apiPut('/api/v1/delivery-notes/' . $created['id'], [
            'notes' => 'Note actualizate',
            'currency' => 'EUR',
            'lines' => [
                [
                    'description' => 'Linie actualizata',
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
        $updated = $response['deliveryNote'];

        $this->assertResponseStatusCodeSame(200);
        $this->assertEquals('Note actualizate', $updated['notes']);
        $this->assertEquals('EUR', $updated['currency']);
        $this->assertCount(1, $updated['lines']);
        $this->assertEquals('Linie actualizata', $updated['lines'][0]['description']);
        // Verify recalculated totals: 5 × 200 = 1000, VAT 19% = 190, total = 1190
        $this->assertEquals('1000.00', $updated['subtotal']);
        $this->assertEquals('190.00', $updated['vatTotal']);
        $this->assertEquals('1190.00', $updated['total']);
    }

    public function testUpdateDeliveryNoteChangesDocumentSeries(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $created = $this->createDraftDeliveryNote($companyId);
        $series = $this->createDeliveryNoteSeries($companyId);

        $response = $this->apiPut('/api/v1/delivery-notes/' . $created['id'], [
            'documentSeriesId' => $series['id'],
        ], ['X-Company' => $companyId]);
        $updated = $response['deliveryNote'];

        $this->assertResponseStatusCodeSame(200);
        $this->assertNotNull($updated['documentSeries']);
        $this->assertEquals($series['id'], $updated['documentSeries']['id']);
    }

    public function testUpdateNonDraftRejected(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $series = $this->createDeliveryNoteSeries($companyId);
        $created = $this->createDraftDeliveryNote($companyId, $series['id']);

        // Issue the delivery note
        $this->apiPost('/api/v1/delivery-notes/' . $created['id'] . '/issue', [], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);

        // Cancel it so it is no longer editable
        $this->apiPost('/api/v1/delivery-notes/' . $created['id'] . '/cancel', [], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);

        // Try to update cancelled delivery note
        $this->apiPut('/api/v1/delivery-notes/' . $created['id'], [
            'notes' => 'Trebuie sa esueze',
        ], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(422);
    }

    // -------------------------------------------------------------------------
    // Delete
    // -------------------------------------------------------------------------

    public function testDeleteDraftDeliveryNote(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $created = $this->createDraftDeliveryNote($companyId);

        $this->apiDelete('/api/v1/delivery-notes/' . $created['id'], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(204);

        // Verify it is soft-deleted
        $this->apiGet('/api/v1/delivery-notes/' . $created['id'], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testDeleteIssuedDeliveryNoteRejected(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $series = $this->createDeliveryNoteSeries($companyId);
        $created = $this->createDraftDeliveryNote($companyId, $series['id']);

        $this->apiPost('/api/v1/delivery-notes/' . $created['id'] . '/issue', [], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);

        $this->apiDelete('/api/v1/delivery-notes/' . $created['id'], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(422);
    }

    // -------------------------------------------------------------------------
    // Issue
    // -------------------------------------------------------------------------

    public function testIssueDeliveryNote(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $series = $this->createDeliveryNoteSeries($companyId);
        $created = $this->createDraftDeliveryNote($companyId, $series['id']);
        $this->assertStringStartsWith('AVIZ-', $created['number']);

        $result = $this->apiPost('/api/v1/delivery-notes/' . $created['id'] . '/issue', [], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertEquals('issued', $result['status']);
        $this->assertNotNull($result['issuedAt']);
        $this->assertStringStartsWith($series['prefix'], $result['number']);
    }

    public function testIssueAssignsDefaultSeriesWhenNoneSet(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        // Create a default delivery_note series so auto-assignment has something to pick
        $this->createDeliveryNoteSeries($companyId);

        // Create delivery note without specifying a series
        $body = [
            'issueDate' => '2026-02-14',
            'currency' => 'RON',
            'lines' => [
                [
                    'description' => 'Produs auto-serie',
                    'quantity' => '1.00',
                    'unitOfMeasure' => 'buc',
                    'unitPrice' => '100.00',
                    'vatRate' => '19.00',
                    'vatCategoryCode' => 'S',
                    'discount' => '0.00',
                    'discountPercent' => '0.00',
                ],
            ],
        ];
        $createResponse = $this->apiPost('/api/v1/delivery-notes', $body, ['X-Company' => $companyId]);
        $created = $createResponse['deliveryNote'];
        $this->assertResponseStatusCodeSame(201);
        $this->assertStringStartsWith('AVIZ-', $created['number']);

        // Issue — should auto-assign the series and produce a proper number
        $result = $this->apiPost('/api/v1/delivery-notes/' . $created['id'] . '/issue', [], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertEquals('issued', $result['status']);
        $this->assertNotNull($result['issuedAt']);
        // Number should no longer be a AVIZ- draft number
        $this->assertStringNotContainsString('AVIZ-', $result['number']);
    }

    public function testIssueNonDraftRejected(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $series = $this->createDeliveryNoteSeries($companyId);
        $created = $this->createDraftDeliveryNote($companyId, $series['id']);

        // Issue once
        $this->apiPost('/api/v1/delivery-notes/' . $created['id'] . '/issue', [], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);

        // Try to issue again
        $this->apiPost('/api/v1/delivery-notes/' . $created['id'] . '/issue', [], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(422);
    }

    public function testAutoNumberingSequence(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $series = $this->createDeliveryNoteSeries($companyId);

        $dn1 = $this->createDraftDeliveryNote($companyId, $series['id']);
        $dn2 = $this->createDraftDeliveryNote($companyId, $series['id']);

        // Both should be drafts initially
        $this->assertStringStartsWith('AVIZ-', $dn1['number']);
        $this->assertStringStartsWith('AVIZ-', $dn2['number']);

        // Issue both
        $this->apiPost('/api/v1/delivery-notes/' . $dn1['id'] . '/issue', [], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);
        $this->apiPost('/api/v1/delivery-notes/' . $dn2['id'] . '/issue', [], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);

        $issued1 = $this->apiGet('/api/v1/delivery-notes/' . $dn1['id'], ['X-Company' => $companyId]);
        $issued2 = $this->apiGet('/api/v1/delivery-notes/' . $dn2['id'], ['X-Company' => $companyId]);

        $this->assertStringStartsWith($series['prefix'], $issued1['number']);
        $this->assertStringStartsWith($series['prefix'], $issued2['number']);
        $this->assertNotEquals($issued1['number'], $issued2['number']);

        // Extract numeric portions and verify sequential ordering
        $num1 = (int) ltrim(substr($issued1['number'], strlen($series['prefix'])), '0');
        $num2 = (int) ltrim(substr($issued2['number'], strlen($series['prefix'])), '0');
        $this->assertEquals($num1 + 1, $num2);
    }

    // -------------------------------------------------------------------------
    // Cancel & Restore
    // -------------------------------------------------------------------------

    public function testCancelDraftDeliveryNote(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $created = $this->createDraftDeliveryNote($companyId);

        $result = $this->apiPost('/api/v1/delivery-notes/' . $created['id'] . '/cancel', [], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertEquals('cancelled', $result['status']);
        $this->assertNotNull($result['cancelledAt']);
    }

    public function testCancelIssuedDeliveryNote(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $series = $this->createDeliveryNoteSeries($companyId);
        $created = $this->createDraftDeliveryNote($companyId, $series['id']);

        $this->apiPost('/api/v1/delivery-notes/' . $created['id'] . '/issue', [], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);

        $result = $this->apiPost('/api/v1/delivery-notes/' . $created['id'] . '/cancel', [], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertEquals('cancelled', $result['status']);
        $this->assertNotNull($result['cancelledAt']);
    }

    public function testCancelAlreadyCancelledRejected(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $created = $this->createDraftDeliveryNote($companyId);

        $this->apiPost('/api/v1/delivery-notes/' . $created['id'] . '/cancel', [], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);

        // Try to cancel again
        $this->apiPost('/api/v1/delivery-notes/' . $created['id'] . '/cancel', [], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(422);
    }

    public function testRestoreCancelledDeliveryNote(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $created = $this->createDraftDeliveryNote($companyId);

        // Cancel first
        $this->apiPost('/api/v1/delivery-notes/' . $created['id'] . '/cancel', [], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);

        // Restore
        $result = $this->apiPost('/api/v1/delivery-notes/' . $created['id'] . '/restore', [], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertEquals('draft', $result['status']);
        $this->assertNull($result['cancelledAt']);
    }

    public function testRestoreNonCancelledRejected(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        // A fresh draft is not cancelled, so restore should fail
        $created = $this->createDraftDeliveryNote($companyId);

        $this->apiPost('/api/v1/delivery-notes/' . $created['id'] . '/restore', [], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(422);
    }

    // -------------------------------------------------------------------------
    // Convert to Invoice
    // -------------------------------------------------------------------------

    public function testConvertIssuedDeliveryNoteToInvoice(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        // Ensure there is a default invoice series so the conversion can assign one
        $this->createInvoiceSeries($companyId);

        $series = $this->createDeliveryNoteSeries($companyId);
        $created = $this->createDraftDeliveryNote($companyId, $series['id']);

        $this->apiPost('/api/v1/delivery-notes/' . $created['id'] . '/issue', [], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);

        $invoice = $this->apiPost('/api/v1/delivery-notes/' . $created['id'] . '/convert', [], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(201);
        $this->assertArrayHasKey('id', $invoice);
        $this->assertEquals('draft', $invoice['status']);
        $this->assertCount(1, $invoice['lines']);
        $this->assertEquals('Marfa test', $invoice['lines'][0]['description']);

        // Verify the delivery note is now marked as converted
        $dn = $this->apiGet('/api/v1/delivery-notes/' . $created['id'], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);
        $this->assertEquals('converted', $dn['status']);
        $this->assertNotNull($dn['convertedInvoice']);
    }

    public function testConvertDraftDeliveryNoteRejected(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        // Draft delivery notes cannot be converted (only ISSUED can)
        $created = $this->createDraftDeliveryNote($companyId);

        $this->apiPost('/api/v1/delivery-notes/' . $created['id'] . '/convert', [], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(422);
    }

    // -------------------------------------------------------------------------
    // Bulk Convert to Invoice
    // -------------------------------------------------------------------------

    public function testBulkConvertToInvoice(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $this->createInvoiceSeries($companyId);
        $client = $this->createTestClient($companyId);
        $series = $this->createDeliveryNoteSeries($companyId);

        $dn1 = $this->createDraftDeliveryNote($companyId, $series['id'], $client['id']);
        $dn2 = $this->createDraftDeliveryNote($companyId, $series['id'], $client['id']);

        $this->apiPost('/api/v1/delivery-notes/' . $dn1['id'] . '/issue', [], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);
        $this->apiPost('/api/v1/delivery-notes/' . $dn2['id'] . '/issue', [], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);

        $invoice = $this->apiPost('/api/v1/delivery-notes/bulk-convert', [
            'ids' => [$dn1['id'], $dn2['id']],
        ], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(201);
        $this->assertArrayHasKey('id', $invoice);
        $this->assertEquals('draft', $invoice['status']);
        // Invoice should contain lines from both delivery notes (1 line each = 2 lines total)
        $this->assertCount(2, $invoice['lines']);

        // Both delivery notes should be converted
        $updated1 = $this->apiGet('/api/v1/delivery-notes/' . $dn1['id'], ['X-Company' => $companyId]);
        $updated2 = $this->apiGet('/api/v1/delivery-notes/' . $dn2['id'], ['X-Company' => $companyId]);
        $this->assertEquals('converted', $updated1['status']);
        $this->assertEquals('converted', $updated2['status']);
    }

    public function testBulkConvertMixedCurrenciesRejected(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $client = $this->createTestClient($companyId);
        $series = $this->createDeliveryNoteSeries($companyId);

        // Create and issue delivery note in RON
        $dn1 = $this->createDraftDeliveryNote($companyId, $series['id'], $client['id']);
        $this->apiPost('/api/v1/delivery-notes/' . $dn1['id'] . '/issue', [], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);

        // Create and issue delivery note in EUR
        $body = [
            'issueDate' => '2026-02-14',
            'currency' => 'EUR',
            'clientId' => $client['id'],
            'lines' => [
                [
                    'description' => 'Produs EUR',
                    'quantity' => '2.00',
                    'unitOfMeasure' => 'buc',
                    'unitPrice' => '50.00',
                    'vatRate' => '19.00',
                    'vatCategoryCode' => 'S',
                    'discount' => '0.00',
                    'discountPercent' => '0.00',
                ],
            ],
        ];
        $dn2Response = $this->apiPost('/api/v1/delivery-notes', $body, ['X-Company' => $companyId]);
        $dn2 = $dn2Response['deliveryNote'];
        $this->assertResponseStatusCodeSame(201);
        $this->apiPost('/api/v1/delivery-notes/' . $dn2['id'] . '/issue', [], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);

        $result = $this->apiPost('/api/v1/delivery-notes/bulk-convert', [
            'ids' => [$dn1['id'], $dn2['id']],
        ], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testBulkConvertMixedClientsRejected(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $client1 = $this->createTestClient($companyId);
        $client2 = $this->createTestClient($companyId);
        $series = $this->createDeliveryNoteSeries($companyId);

        $dn1 = $this->createDraftDeliveryNote($companyId, $series['id'], $client1['id']);
        $dn2 = $this->createDraftDeliveryNote($companyId, $series['id'], $client2['id']);

        $this->apiPost('/api/v1/delivery-notes/' . $dn1['id'] . '/issue', [], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);
        $this->apiPost('/api/v1/delivery-notes/' . $dn2['id'] . '/issue', [], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);

        $result = $this->apiPost('/api/v1/delivery-notes/bulk-convert', [
            'ids' => [$dn1['id'], $dn2['id']],
        ], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testBulkConvertRequiresIds(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $result = $this->apiPost('/api/v1/delivery-notes/bulk-convert', [], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(400);
    }

    // -------------------------------------------------------------------------
    // Storno
    // -------------------------------------------------------------------------

    public function testStornoDeliveryNote(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $series = $this->createDeliveryNoteSeries($companyId);
        $created = $this->createDraftDeliveryNote($companyId, $series['id']);

        $this->apiPost('/api/v1/delivery-notes/' . $created['id'] . '/issue', [], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);

        $storno = $this->apiPost('/api/v1/delivery-notes/' . $created['id'] . '/storno', [], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(201);
        $this->assertArrayHasKey('id', $storno);
        $this->assertEquals('draft', $storno['status']);
        // Storno note should have negated quantities on its lines
        $this->assertCount(1, $storno['lines']);
        $this->assertEquals('-10.0000', $storno['lines'][0]['quantity']);
        // Storno note is a new document
        $this->assertNotEquals($created['id'], $storno['id']);
    }

    public function testStornoDefaultSeriesAssigned(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $series = $this->createDeliveryNoteSeries($companyId);
        $created = $this->createDraftDeliveryNote($companyId, $series['id']);

        $this->apiPost('/api/v1/delivery-notes/' . $created['id'] . '/issue', [], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);

        $storno = $this->apiPost('/api/v1/delivery-notes/' . $created['id'] . '/storno', [], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(201);
        // The storno note should have the default delivery_note series assigned
        $this->assertNotNull($storno['documentSeries']);
    }

    // -------------------------------------------------------------------------
    // Create from Proforma
    // -------------------------------------------------------------------------

    public function testCreateFromProforma(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $proforma = $this->createProforma($companyId);

        $dn = $this->apiPost('/api/v1/delivery-notes/from-proforma', [
            'proformaId' => $proforma['id'],
        ], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(201);
        $this->assertArrayHasKey('id', $dn);
        $this->assertEquals('draft', $dn['status']);
        $this->assertStringStartsWith('AVIZ-', $dn['number']);
        // Lines should be copied from the proforma
        $this->assertCount(1, $dn['lines']);
        $this->assertEquals('Servicii consultanta', $dn['lines'][0]['description']);
        $this->assertEquals('5.0000', $dn['lines'][0]['quantity']);
        // Verify totals copied correctly: 5 × 200 = 1000, VAT 19% = 190, total = 1190
        $this->assertEquals('1000.00', $dn['subtotal']);
        $this->assertEquals('190.00', $dn['vatTotal']);
        $this->assertEquals('1190.00', $dn['total']);
    }

    public function testCreateFromProformaRequiresProformaId(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $this->apiPost('/api/v1/delivery-notes/from-proforma', [], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testCreateFromProformaNonExistentProformaNotFound(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $this->apiPost('/api/v1/delivery-notes/from-proforma', [
            'proformaId' => '00000000-0000-0000-0000-000000000000',
        ], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(404);
    }

    // -------------------------------------------------------------------------
    // Client serialization on detail endpoint
    // -------------------------------------------------------------------------

    public function testDeliveryNoteDetailReturnsClientData(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $client = $this->createTestClient($companyId);
        $created = $this->createDraftDeliveryNote($companyId, null, $client['id']);

        $data = $this->apiGet('/api/v1/delivery-notes/' . $created['id'], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertArrayHasKey('client', $data);
        $this->assertNotNull($data['client']);
        $this->assertArrayHasKey('id', $data['client']);
        $this->assertArrayHasKey('name', $data['client']);
        $this->assertArrayHasKey('cui', $data['client']);
        $this->assertArrayHasKey('address', $data['client']);
        $this->assertArrayHasKey('email', $data['client']);
        $this->assertArrayHasKey('city', $data['client']);
        $this->assertArrayHasKey('country', $data['client']);
        $this->assertEquals($client['id'], $data['client']['id']);
        $this->assertEquals($client['name'], $data['client']['name']);
    }

    public function testDeliveryNoteListReturnsClientName(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $client = $this->createTestClient($companyId);
        $this->createDraftDeliveryNote($companyId, null, $client['id']);

        $list = $this->apiGet('/api/v1/delivery-notes', ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertNotEmpty($list['data']);

        // Find the delivery note we just created in the list
        $found = null;
        foreach ($list['data'] as $item) {
            if (!empty($item['client']) && $item['client']['id'] === $client['id']) {
                $found = $item;
                break;
            }
        }
        $this->assertNotNull($found, 'Delivery note with the created client was not found in the list.');
        $this->assertEquals($client['name'], $found['client']['name']);
    }
}
