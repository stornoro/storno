<?php

namespace App\Tests\Api;

class ReceiptCrudTest extends ApiTestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function createReceiptSeries(string $companyId): array
    {
        $prefix = 'BON' . substr(md5(uniqid('', true) . microtime(true) . random_int(0, PHP_INT_MAX)), 0, 8);
        $series = $this->apiPost('/api/v1/document-series', [
            'prefix' => $prefix,
            'type' => 'receipt',
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

    private function createDraftReceipt(string $companyId, ?string $seriesId = null, ?string $clientId = null): array
    {
        $body = [
            'issueDate' => '2026-02-14',
            'currency' => 'RON',
            'notes' => 'Test bon fiscal',
            'paymentMethod' => 'cash',
            'lines' => [
                [
                    'description' => 'Produs test',
                    'quantity' => '5.00',
                    'unitOfMeasure' => 'buc',
                    'unitPrice' => '100.00',
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

        $data = $this->apiPost('/api/v1/receipts', $body, ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(201);

        return $data;
    }

    // -------------------------------------------------------------------------
    // List & Show
    // -------------------------------------------------------------------------

    public function testListReceipts(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $data = $this->apiGet('/api/v1/receipts', ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertArrayHasKey('data', $data);
        $this->assertIsArray($data['data']);
        $this->assertArrayHasKey('total', $data);
        $this->assertArrayHasKey('page', $data);
        $this->assertArrayHasKey('limit', $data);
    }

    public function testListReceiptsRequiresCompany(): void
    {
        $this->login();

        $this->apiGet('/api/v1/receipts');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testListReceiptsUnauthenticated(): void
    {
        $this->apiGet('/api/v1/receipts');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testShowReceipt(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $created = $this->createDraftReceipt($companyId);

        $data = $this->apiGet('/api/v1/receipts/' . $created['id'], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertEquals($created['id'], $data['id']);
        $this->assertArrayHasKey('number', $data);
        $this->assertArrayHasKey('status', $data);
        $this->assertArrayHasKey('lines', $data);
        $this->assertIsArray($data['lines']);
    }

    public function testShowReceiptNotFound(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $this->apiGet('/api/v1/receipts/00000000-0000-0000-0000-000000000000', ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(404);
    }

    // -------------------------------------------------------------------------
    // Create
    // -------------------------------------------------------------------------

    public function testCreateReceiptSuccess(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $data = $this->createDraftReceipt($companyId);

        $this->assertArrayHasKey('id', $data);
        $this->assertArrayHasKey('number', $data);
        $this->assertEquals('draft', $data['status']);
        $this->assertEquals('RON', $data['currency']);
        $this->assertStringStartsWith('BON-', $data['number']);
        $this->assertCount(1, $data['lines']);
        $this->assertEquals('Produs test', $data['lines'][0]['description']);
        $this->assertEquals('cash', $data['paymentMethod']);

        // Verify totals: 5 × 100 = 500, VAT 19% = 95, total = 595
        $this->assertEquals('500.00', $data['subtotal']);
        $this->assertEquals('95.00', $data['vatTotal']);
        $this->assertEquals('595.00', $data['total']);
    }

    public function testCreateReceiptWithExplicitSeries(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $series = $this->createReceiptSeries($companyId);
        $data = $this->createDraftReceipt($companyId, $series['id']);

        $this->assertArrayHasKey('id', $data);
        $this->assertEquals('draft', $data['status']);
        $this->assertNotNull($data['documentSeries']);
        $this->assertEquals($series['id'], $data['documentSeries']['id']);
    }

    public function testCreateReceiptWithClient(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $client = $this->createTestClient($companyId);
        $data = $this->createDraftReceipt($companyId, null, $client['id']);

        $this->assertResponseStatusCodeSame(201);
        $this->assertNotNull($data['client']);
        $this->assertEquals($client['id'], $data['client']['id']);
        $this->assertEquals($client['name'], $data['client']['name']);
    }

    public function testCreateReceiptWithoutClientSucceeds(): void
    {
        // Receipts can be B2C (no client required)
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $data = $this->createDraftReceipt($companyId);

        $this->assertResponseStatusCodeSame(201);
        $this->assertNull($data['client']);
    }

    public function testCreateReceiptWithReceiptSpecificFields(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $body = [
            'issueDate' => '2026-02-14',
            'currency' => 'RON',
            'paymentMethod' => 'card',
            'cashPayment' => '0.00',
            'cardPayment' => '595.00',
            'cashRegisterName' => 'Casa 1',
            'fiscalNumber' => 'FISCAL-001',
            'customerName' => 'Ion Popescu',
            'customerCif' => '1234567890123',
            'lines' => [
                [
                    'description' => 'Produs card',
                    'quantity' => '1.00',
                    'unitOfMeasure' => 'buc',
                    'unitPrice' => '500.00',
                    'vatRate' => '19.00',
                    'vatCategoryCode' => 'S',
                    'discount' => '0.00',
                    'discountPercent' => '0.00',
                ],
            ],
        ];

        $data = $this->apiPost('/api/v1/receipts', $body, ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(201);
        $this->assertEquals('card', $data['paymentMethod']);
        $this->assertEquals('0.00', $data['cashPayment']);
        $this->assertEquals('595.00', $data['cardPayment']);
        $this->assertEquals('Casa 1', $data['cashRegisterName']);
        $this->assertEquals('FISCAL-001', $data['fiscalNumber']);
        $this->assertEquals('Ion Popescu', $data['customerName']);
        $this->assertEquals('1234567890123', $data['customerCif']);
    }

    public function testCreateReceiptValidationFailureMissingLines(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $this->apiPost('/api/v1/receipts', [
            'issueDate' => '2026-02-14',
            'currency' => 'RON',
        ], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testCreateReceiptLineValidationMissingDescription(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $this->apiPost('/api/v1/receipts', [
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

    public function testCreateReceiptLineValidationNonPositiveQuantity(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $this->apiPost('/api/v1/receipts', [
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

    // -------------------------------------------------------------------------
    // Update
    // -------------------------------------------------------------------------

    public function testUpdateDraftReceipt(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $created = $this->createDraftReceipt($companyId);

        $updated = $this->apiPut('/api/v1/receipts/' . $created['id'], [
            'notes' => 'Note actualizate bon',
            'currency' => 'EUR',
            'paymentMethod' => 'card',
            'lines' => [
                [
                    'description' => 'Linie actualizata',
                    'quantity' => '2.00',
                    'unitOfMeasure' => 'buc',
                    'unitPrice' => '300.00',
                    'vatRate' => '19.00',
                    'vatCategoryCode' => 'S',
                    'discount' => '0.00',
                    'discountPercent' => '0.00',
                ],
            ],
        ], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertEquals('Note actualizate bon', $updated['notes']);
        $this->assertEquals('EUR', $updated['currency']);
        $this->assertEquals('card', $updated['paymentMethod']);
        $this->assertCount(1, $updated['lines']);
        $this->assertEquals('Linie actualizata', $updated['lines'][0]['description']);
        // Verify recalculated totals: 2 × 300 = 600, VAT 19% = 114, total = 714
        $this->assertEquals('600.00', $updated['subtotal']);
        $this->assertEquals('114.00', $updated['vatTotal']);
        $this->assertEquals('714.00', $updated['total']);
    }

    public function testUpdateReceiptChangesDocumentSeries(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $created = $this->createDraftReceipt($companyId);
        $series = $this->createReceiptSeries($companyId);

        $updated = $this->apiPut('/api/v1/receipts/' . $created['id'], [
            'documentSeriesId' => $series['id'],
        ], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertNotNull($updated['documentSeries']);
        $this->assertEquals($series['id'], $updated['documentSeries']['id']);
    }

    public function testUpdateCancelledReceiptRejected(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $created = $this->createDraftReceipt($companyId);

        // Cancel it so it is no longer editable
        $this->apiPost('/api/v1/receipts/' . $created['id'] . '/cancel', [], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);

        $this->apiPut('/api/v1/receipts/' . $created['id'], [
            'notes' => 'Trebuie sa esueze',
        ], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(422);
    }

    // -------------------------------------------------------------------------
    // Delete
    // -------------------------------------------------------------------------

    public function testDeleteDraftReceipt(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $created = $this->createDraftReceipt($companyId);

        $this->apiDelete('/api/v1/receipts/' . $created['id'], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(204);

        // Verify it is soft-deleted
        $this->apiGet('/api/v1/receipts/' . $created['id'], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testDeleteIssuedReceiptRejected(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $series = $this->createReceiptSeries($companyId);
        $created = $this->createDraftReceipt($companyId, $series['id']);

        $this->apiPost('/api/v1/receipts/' . $created['id'] . '/issue', [], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);

        $this->apiDelete('/api/v1/receipts/' . $created['id'], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(422);
    }

    // -------------------------------------------------------------------------
    // Issue
    // -------------------------------------------------------------------------

    public function testIssueReceipt(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $series = $this->createReceiptSeries($companyId);
        $created = $this->createDraftReceipt($companyId, $series['id']);
        $this->assertStringStartsWith('BON-', $created['number']);

        $result = $this->apiPost('/api/v1/receipts/' . $created['id'] . '/issue', [], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertEquals('issued', $result['status']);
        $this->assertNotNull($result['issuedAt']);
        $this->assertStringStartsWith($series['prefix'], $result['number']);
    }

    public function testIssueAssignsDefaultSeriesWhenNoneSet(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        // Create a default receipt series so auto-assignment has something to pick
        $this->createReceiptSeries($companyId);

        // Create receipt without specifying a series
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
        $created = $this->apiPost('/api/v1/receipts', $body, ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(201);
        $this->assertStringStartsWith('BON-', $created['number']);

        // Issue — should auto-assign the series and produce a proper number
        $result = $this->apiPost('/api/v1/receipts/' . $created['id'] . '/issue', [], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertEquals('issued', $result['status']);
        $this->assertNotNull($result['issuedAt']);
        // Number should no longer be a BON- draft number
        $this->assertStringNotContainsString('BON-', $result['number']);
    }

    public function testIssueNonDraftReceiptRejected(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $series = $this->createReceiptSeries($companyId);
        $created = $this->createDraftReceipt($companyId, $series['id']);

        // Issue once
        $this->apiPost('/api/v1/receipts/' . $created['id'] . '/issue', [], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);

        // Try to issue again
        $this->apiPost('/api/v1/receipts/' . $created['id'] . '/issue', [], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(422);
    }

    public function testAutoNumberingSequence(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $series = $this->createReceiptSeries($companyId);

        $r1 = $this->createDraftReceipt($companyId, $series['id']);
        $r2 = $this->createDraftReceipt($companyId, $series['id']);

        // Both should be drafts initially
        $this->assertStringStartsWith('BON-', $r1['number']);
        $this->assertStringStartsWith('BON-', $r2['number']);

        // Issue both
        $this->apiPost('/api/v1/receipts/' . $r1['id'] . '/issue', [], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);
        $this->apiPost('/api/v1/receipts/' . $r2['id'] . '/issue', [], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);

        $issued1 = $this->apiGet('/api/v1/receipts/' . $r1['id'], ['X-Company' => $companyId]);
        $issued2 = $this->apiGet('/api/v1/receipts/' . $r2['id'], ['X-Company' => $companyId]);

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

    public function testCancelDraftReceipt(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $created = $this->createDraftReceipt($companyId);

        $result = $this->apiPost('/api/v1/receipts/' . $created['id'] . '/cancel', [], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertEquals('cancelled', $result['status']);
        $this->assertNotNull($result['cancelledAt']);
    }

    public function testCancelIssuedReceipt(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $series = $this->createReceiptSeries($companyId);
        $created = $this->createDraftReceipt($companyId, $series['id']);

        $this->apiPost('/api/v1/receipts/' . $created['id'] . '/issue', [], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);

        $result = $this->apiPost('/api/v1/receipts/' . $created['id'] . '/cancel', [], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertEquals('cancelled', $result['status']);
        $this->assertNotNull($result['cancelledAt']);
    }

    public function testCancelAlreadyCancelledReceiptRejected(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $created = $this->createDraftReceipt($companyId);

        $this->apiPost('/api/v1/receipts/' . $created['id'] . '/cancel', [], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);

        // Try to cancel again
        $this->apiPost('/api/v1/receipts/' . $created['id'] . '/cancel', [], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(422);
    }

    public function testCancelInvoicedReceiptRejected(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $this->createInvoiceSeries($companyId);
        $series = $this->createReceiptSeries($companyId);
        $created = $this->createDraftReceipt($companyId, $series['id']);

        // Issue then convert to invoice
        $this->apiPost('/api/v1/receipts/' . $created['id'] . '/issue', [], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);
        $this->apiPost('/api/v1/receipts/' . $created['id'] . '/convert', [], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(201);

        // Cancelling an invoiced receipt should be rejected
        $this->apiPost('/api/v1/receipts/' . $created['id'] . '/cancel', [], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(422);
    }

    public function testRestoreCancelledReceipt(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $created = $this->createDraftReceipt($companyId);

        // Cancel first
        $this->apiPost('/api/v1/receipts/' . $created['id'] . '/cancel', [], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);

        // Restore
        $result = $this->apiPost('/api/v1/receipts/' . $created['id'] . '/restore', [], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertEquals('draft', $result['status']);
        $this->assertNull($result['cancelledAt']);
    }

    public function testRestoreNonCancelledReceiptRejected(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        // A fresh draft is not cancelled, so restore should fail
        $created = $this->createDraftReceipt($companyId);

        $this->apiPost('/api/v1/receipts/' . $created['id'] . '/restore', [], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(422);
    }

    // -------------------------------------------------------------------------
    // Convert to Invoice
    // -------------------------------------------------------------------------

    public function testConvertIssuedReceiptToInvoice(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        // Ensure there is a default invoice series so the conversion can assign one
        $this->createInvoiceSeries($companyId);

        $series = $this->createReceiptSeries($companyId);
        $created = $this->createDraftReceipt($companyId, $series['id']);

        $this->apiPost('/api/v1/receipts/' . $created['id'] . '/issue', [], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);

        $invoice = $this->apiPost('/api/v1/receipts/' . $created['id'] . '/convert', [], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(201);
        $this->assertArrayHasKey('id', $invoice);
        $this->assertEquals('draft', $invoice['status']);
        $this->assertCount(1, $invoice['lines']);
        $this->assertEquals('Produs test', $invoice['lines'][0]['description']);

        // Verify the receipt is now marked as invoiced
        $receipt = $this->apiGet('/api/v1/receipts/' . $created['id'], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);
        $this->assertEquals('invoiced', $receipt['status']);
        $this->assertNotNull($receipt['convertedInvoice']);
    }

    public function testConvertDraftReceiptRejected(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        // Draft receipts cannot be converted (only ISSUED can)
        $created = $this->createDraftReceipt($companyId);

        $this->apiPost('/api/v1/receipts/' . $created['id'] . '/convert', [], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testConvertUsesDefaultInvoiceSeries(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $series = $this->createReceiptSeries($companyId);
        $created = $this->createDraftReceipt($companyId, $series['id']);

        $this->apiPost('/api/v1/receipts/' . $created['id'] . '/issue', [], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);

        $invoice = $this->apiPost('/api/v1/receipts/' . $created['id'] . '/convert', [], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(201);
        // The resulting invoice should have the default invoice series assigned (whichever is the
        // company's default). We just assert that a series IS assigned — not a specific ID — because
        // the fixture database may already have a default invoice series for this company.
        $this->assertNotNull($invoice['documentSeries']);
        $this->assertArrayHasKey('id', $invoice['documentSeries']);
        $this->assertArrayHasKey('prefix', $invoice['documentSeries']);
    }

    public function testConvertPreservesReceiptLines(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $this->createInvoiceSeries($companyId);
        $series = $this->createReceiptSeries($companyId);

        // Create receipt with two lines
        $body = [
            'issueDate' => '2026-02-14',
            'currency' => 'RON',
            'lines' => [
                [
                    'description' => 'Produs A',
                    'quantity' => '3.00',
                    'unitOfMeasure' => 'buc',
                    'unitPrice' => '50.00',
                    'vatRate' => '19.00',
                    'vatCategoryCode' => 'S',
                    'discount' => '0.00',
                    'discountPercent' => '0.00',
                ],
                [
                    'description' => 'Produs B',
                    'quantity' => '1.00',
                    'unitOfMeasure' => 'buc',
                    'unitPrice' => '200.00',
                    'vatRate' => '9.00',
                    'vatCategoryCode' => 'S',
                    'discount' => '0.00',
                    'discountPercent' => '0.00',
                ],
            ],
        ];
        $created = $this->apiPost('/api/v1/receipts', $body, ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(201);

        $this->apiPost('/api/v1/receipts/' . $created['id'] . '/issue', [], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);

        $invoice = $this->apiPost('/api/v1/receipts/' . $created['id'] . '/convert', [], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(201);
        $this->assertCount(2, $invoice['lines']);

        $descriptions = array_column($invoice['lines'], 'description');
        $this->assertContains('Produs A', $descriptions);
        $this->assertContains('Produs B', $descriptions);
    }

    // -------------------------------------------------------------------------
    // Client serialization on detail endpoint
    // -------------------------------------------------------------------------

    public function testReceiptDetailReturnsClientData(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $client = $this->createTestClient($companyId);
        $created = $this->createDraftReceipt($companyId, null, $client['id']);

        $data = $this->apiGet('/api/v1/receipts/' . $created['id'], ['X-Company' => $companyId]);

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

    public function testReceiptDetailReturnsClientCnpAndRegistrationNumber(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $client = $this->createTestClient($companyId);

        $receipt = $this->createDraftReceipt($companyId, null, $client['id']);
        $data = $this->apiGet('/api/v1/receipts/' . $receipt['id'], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertNotNull($data['client']);
        // The receipt:detail group should expose cnp and registrationNumber via Client entity groups
        $this->assertArrayHasKey('cnp', $data['client']);
        $this->assertArrayHasKey('registrationNumber', $data['client']);
    }

    public function testReceiptListReturnsClientName(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $client = $this->createTestClient($companyId);
        $this->createDraftReceipt($companyId, null, $client['id']);

        $list = $this->apiGet('/api/v1/receipts', ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertNotEmpty($list['data']);

        // Find the receipt we just created in the list
        $found = null;
        foreach ($list['data'] as $item) {
            if (!empty($item['client']) && $item['client']['id'] === $client['id']) {
                $found = $item;
                break;
            }
        }
        $this->assertNotNull($found, 'Receipt with the created client was not found in the list.');
        $this->assertEquals($client['name'], $found['client']['name']);
        $this->assertEquals($client['cui'], $found['client']['cui']);
    }
}
