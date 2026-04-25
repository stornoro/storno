<?php

namespace App\Tests\Api;

/**
 * Functional tests for the receipt refund flow.
 *
 * Each test creates its own company, series, and receipt so tests remain
 * fully isolated from one another and from existing fixture data.
 *
 * The refund endpoint is:  POST /api/v1/receipts/{uuid}/refund
 * Optional body:           { "lineSelections": [{ "sourceLineId": "<uuid>", "quantity": "1" }] }
 * Empty / omitted body     => full-refund (all lines, all quantities).
 */
class ReceiptRefundTest extends ApiTestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Creates a unique receipt-type document series for a company.
     */
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

    /**
     * Creates a draft receipt with a single line.
     * qty=2, unitPrice=10, vatRate=19 (VAT-exclusive) by default.
     * total gross = qty * unitPrice * 1.19
     */
    private function createSingleLineReceipt(
        string $companyId,
        string $seriesId,
        float $qty = 2.0,
        float $unitPrice = 10.00,
        float $vatRate = 19.00,
        ?string $cashPayment = null,
        ?string $cardPayment = null,
    ): array {
        $body = [
            'issueDate' => '2026-04-25',
            'currency' => 'RON',
            'paymentMethod' => 'cash',
            'cashPayment' => $cashPayment,
            'cardPayment' => $cardPayment,
            'documentSeriesId' => $seriesId,
            'lines' => [
                [
                    'description' => 'Test product',
                    'quantity' => number_format($qty, 2, '.', ''),
                    'unitOfMeasure' => 'buc',
                    'unitPrice' => number_format($unitPrice, 2, '.', ''),
                    'vatRate' => number_format($vatRate, 2, '.', ''),
                    'vatCategoryCode' => 'S',
                    'discount' => '0.00',
                    'discountPercent' => '0.00',
                ],
            ],
        ];

        $data = $this->apiPost('/api/v1/receipts', $body, ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(201);

        return $data;
    }

    /**
     * Creates a draft receipt with two named lines.
     * Line A: qty=2, price=10 RON
     * Line B: qty=1, price=30 RON
     * All VAT-exclusive at 19%.
     */
    private function createTwoLineReceipt(string $companyId, string $seriesId): array
    {
        $body = [
            'issueDate' => '2026-04-25',
            'currency' => 'RON',
            'paymentMethod' => 'cash',
            'cashPayment' => '59.50',  // (2*10 + 1*30) * 1.19 = 59.50
            'documentSeriesId' => $seriesId,
            'lines' => [
                [
                    'description' => 'Line A',
                    'quantity' => '2.00',
                    'unitOfMeasure' => 'buc',
                    'unitPrice' => '10.00',
                    'vatRate' => '19.00',
                    'vatCategoryCode' => 'S',
                    'discount' => '0.00',
                    'discountPercent' => '0.00',
                ],
                [
                    'description' => 'Line B',
                    'quantity' => '1.00',
                    'unitOfMeasure' => 'buc',
                    'unitPrice' => '30.00',
                    'vatRate' => '19.00',
                    'vatCategoryCode' => 'S',
                    'discount' => '0.00',
                    'discountPercent' => '0.00',
                ],
            ],
        ];

        $data = $this->apiPost('/api/v1/receipts', $body, ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(201);

        return $data;
    }

    /**
     * Issues a draft receipt and returns the updated receipt data.
     */
    private function issueReceipt(string $receiptId, string $companyId): array
    {
        $result = $this->apiPost('/api/v1/receipts/' . $receiptId . '/issue', [], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);
        $this->assertEquals('issued', $result['status']);

        return $result;
    }

    /**
     * Fetches the full receipt detail.
     */
    private function getReceipt(string $receiptId, string $companyId): array
    {
        $data = $this->apiGet('/api/v1/receipts/' . $receiptId, ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);

        return $data;
    }

    // -------------------------------------------------------------------------
    // Test 1: Full refund happy path
    // -------------------------------------------------------------------------

    public function testFullRefundHappyPath(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();
        $series = $this->createReceiptSeries($companyId);

        // Receipt: qty=2 @ 10 RON, VAT 19% → gross = 23.80, cashPayment = 23.80
        $draft = $this->createSingleLineReceipt(
            $companyId,
            $series['id'],
            qty: 2.0,
            unitPrice: 10.00,
            cashPayment: '23.80',
        );
        $parent = $this->issueReceipt($draft['id'], $companyId);

        $refund = $this->apiPost(
            '/api/v1/receipts/' . $parent['id'] . '/refund',
            [],
            ['X-Company' => $companyId],
        );

        $this->assertResponseStatusCodeSame(201);

        // refundOf link
        $this->assertArrayHasKey('refundOf', $refund);
        $this->assertNotNull($refund['refundOf']);
        $this->assertEquals($parent['id'], $refund['refundOf']['id']);

        // Status should be issued (auto-issued by the manager)
        $this->assertEquals('issued', $refund['status']);

        // Lines should be negated
        $this->assertCount(1, $refund['lines']);
        $this->assertEqualsWithDelta(-2.0, (float) $refund['lines'][0]['quantity'], 0.0001);

        // cashPayment should be negated
        $this->assertNotNull($refund['cashPayment']);
        $this->assertEqualsWithDelta(-23.80, (float) $refund['cashPayment'], 0.01);

        // total should be negative
        $this->assertLessThan(0, (float) $refund['total']);
    }

    // -------------------------------------------------------------------------
    // Test 2: Cannot refund a draft
    // -------------------------------------------------------------------------

    public function testCannotRefundDraft(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();
        $series = $this->createReceiptSeries($companyId);

        // Do NOT issue — receipt stays in draft
        $draft = $this->createSingleLineReceipt($companyId, $series['id']);

        $response = $this->apiPost(
            '/api/v1/receipts/' . $draft['id'] . '/refund',
            [],
            ['X-Company' => $companyId],
        );

        $this->assertResponseStatusCodeSame(422);
        $this->assertEquals('Only issued receipts can be refunded.', $response['error']);
    }

    // -------------------------------------------------------------------------
    // Test 3: Cannot refund a refund
    // -------------------------------------------------------------------------

    public function testCannotRefundARefund(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();
        $series = $this->createReceiptSeries($companyId);

        $draft = $this->createSingleLineReceipt($companyId, $series['id']);
        $parent = $this->issueReceipt($draft['id'], $companyId);

        // First refund — succeeds
        $refund = $this->apiPost(
            '/api/v1/receipts/' . $parent['id'] . '/refund',
            [],
            ['X-Company' => $companyId],
        );
        $this->assertResponseStatusCodeSame(201);

        // Attempt to refund the refund itself
        $response = $this->apiPost(
            '/api/v1/receipts/' . $refund['id'] . '/refund',
            [],
            ['X-Company' => $companyId],
        );

        $this->assertResponseStatusCodeSame(422);
        $this->assertEquals('A refund receipt cannot itself be refunded.', $response['error']);
    }

    // -------------------------------------------------------------------------
    // Test 4: Cannot fully-refund twice (empty body = full refund path)
    // -------------------------------------------------------------------------

    public function testCannotFullyRefundTwice(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();
        $series = $this->createReceiptSeries($companyId);

        $draft = $this->createSingleLineReceipt($companyId, $series['id']);
        $parent = $this->issueReceipt($draft['id'], $companyId);

        // First full refund — succeeds
        $this->apiPost(
            '/api/v1/receipts/' . $parent['id'] . '/refund',
            [],
            ['X-Company' => $companyId],
        );
        $this->assertResponseStatusCodeSame(201);

        // Second full refund with empty body — must be rejected
        $response = $this->apiPost(
            '/api/v1/receipts/' . $parent['id'] . '/refund',
            [],
            ['X-Company' => $companyId],
        );

        $this->assertResponseStatusCodeSame(422);
        $this->assertEquals('Receipt has already been refunded.', $response['error']);
    }

    // -------------------------------------------------------------------------
    // Test 5: Partial refund — single line, qty=1 of a qty=2 line
    // -------------------------------------------------------------------------

    public function testPartialRefundSingleLine(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();
        $series = $this->createReceiptSeries($companyId);

        // Receipt: 1 line, qty=2, unitPrice=10, VAT 19% (exclusive)
        // gross per unit = 11.90; total gross = 23.80; cashPayment = 23.80
        $draft = $this->createSingleLineReceipt(
            $companyId,
            $series['id'],
            qty: 2.0,
            unitPrice: 10.00,
            cashPayment: '23.80',
        );
        $parent = $this->issueReceipt($draft['id'], $companyId);

        // Fetch detail to get the line ID
        $parentDetail = $this->getReceipt($parent['id'], $companyId);
        $this->assertCount(1, $parentDetail['lines']);
        $sourceLineId = $parentDetail['lines'][0]['id'];

        $refund = $this->apiPost(
            '/api/v1/receipts/' . $parent['id'] . '/refund',
            ['lineSelections' => [['sourceLineId' => $sourceLineId, 'quantity' => '1']]],
            ['X-Company' => $companyId],
        );

        $this->assertResponseStatusCodeSame(201);

        // Should have exactly one line
        $this->assertCount(1, $refund['lines']);
        $this->assertEqualsWithDelta(-1.0, (float) $refund['lines'][0]['quantity'], 0.0001);

        // cashPayment should be approximately half of the parent's cashPayment (negated)
        // share = 1/2 = 0.5; scaled cashPayment = -23.80 * 0.5 = -11.90
        $this->assertNotNull($refund['cashPayment']);
        $this->assertEqualsWithDelta(-11.90, (float) $refund['cashPayment'], 0.01);

        // total should be approximately half the parent total (negated)
        $this->assertLessThan(0, (float) $refund['total']);
        $this->assertEqualsWithDelta(-11.90, (float) $refund['total'], 0.01);
    }

    // -------------------------------------------------------------------------
    // Test 6: Partial refund multiple lines — proportional payment scaling
    // -------------------------------------------------------------------------

    public function testPartialRefundMultipleLines(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();
        $series = $this->createReceiptSeries($companyId);

        // Receipt:
        //   Line A: qty=2, unitPrice=10, VAT 19%  → gross = 23.80
        //   Line B: qty=1, unitPrice=30, VAT 19%  → gross = 35.70
        //   Total gross = 59.50
        $draft = $this->createTwoLineReceipt($companyId, $series['id']);
        $parent = $this->issueReceipt($draft['id'], $companyId);

        $parentDetail = $this->getReceipt($parent['id'], $companyId);
        $this->assertCount(2, $parentDetail['lines']);

        // Map line IDs by description
        $lineIds = [];
        foreach ($parentDetail['lines'] as $line) {
            $lineIds[$line['description']] = $line['id'];
        }
        $this->assertArrayHasKey('Line A', $lineIds);
        $this->assertArrayHasKey('Line B', $lineIds);

        // Refund: 1 of Line A (gross=11.90) + 1 of Line B (gross=35.70) = total refund gross=47.60
        $refund = $this->apiPost(
            '/api/v1/receipts/' . $parent['id'] . '/refund',
            [
                'lineSelections' => [
                    ['sourceLineId' => $lineIds['Line A'], 'quantity' => '1'],
                    ['sourceLineId' => $lineIds['Line B'], 'quantity' => '1'],
                ],
            ],
            ['X-Company' => $companyId],
        );

        $this->assertResponseStatusCodeSame(201);

        // Exactly two refund lines
        $this->assertCount(2, $refund['lines']);

        // Refund total should be approximately -(11.90 + 35.70) = -47.60
        $this->assertEqualsWithDelta(-47.60, (float) $refund['total'], 0.05);

        // cashPayment scaled:  share = 47.60 / 59.50 ≈ 0.8000; payment = -59.50 * 0.8 = -47.60
        $this->assertNotNull($refund['cashPayment']);
        $this->assertEqualsWithDelta(-47.60, (float) $refund['cashPayment'], 0.05);
    }

    // -------------------------------------------------------------------------
    // Test 7: Multiple sequential partial refunds — quantity pool exhausts correctly
    //
    // The serializer no longer recurses into nested Receipt entities for the
    // refundOf / refundedBy fields (they're returned as slim {id, number}
    // arrays via getRefundOfRef() / getRefundedByRefs()), so multiple partial
    // refunds against the same parent serialize cleanly.
    // -------------------------------------------------------------------------

    public function testMultiplePartialRefundsExhaustPool(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();
        $series = $this->createReceiptSeries($companyId);

        // Receipt: qty=3
        $draft = $this->createSingleLineReceipt($companyId, $series['id'], qty: 3.0);
        $parent = $this->issueReceipt($draft['id'], $companyId);

        $parentDetail = $this->getReceipt($parent['id'], $companyId);
        $sourceLineId = $parentDetail['lines'][0]['id'];

        // First partial refund: qty=1 → succeeds
        $refund1 = $this->apiPost(
            '/api/v1/receipts/' . $parent['id'] . '/refund',
            ['lineSelections' => [['sourceLineId' => $sourceLineId, 'quantity' => '1']]],
            ['X-Company' => $companyId],
        );
        $this->assertResponseStatusCodeSame(201);
        $this->assertEqualsWithDelta(-1.0, (float) $refund1['lines'][0]['quantity'], 0.0001);

        // Second partial refund: qty=2 → succeeds, exhausts the pool
        $refund2 = $this->apiPost(
            '/api/v1/receipts/' . $parent['id'] . '/refund',
            ['lineSelections' => [['sourceLineId' => $sourceLineId, 'quantity' => '2']]],
            ['X-Company' => $companyId],
        );
        $this->assertResponseStatusCodeSame(201);
        $this->assertEqualsWithDelta(-2.0, (float) $refund2['lines'][0]['quantity'], 0.0001);

        // Refund response carries the slim refundOf shape, not a nested entity.
        $this->assertSame($parent['id'], $refund2['refundOf']['id']);
        $this->assertArrayHasKey('number', $refund2['refundOf']);

        // Third refund of qty=1 must be rejected — pool is empty.
        $third = $this->apiPost(
            '/api/v1/receipts/' . $parent['id'] . '/refund',
            ['lineSelections' => [['sourceLineId' => $sourceLineId, 'quantity' => '1']]],
            ['X-Company' => $companyId],
        );
        $this->assertResponseStatusCodeSame(422);
        $this->assertMatchesRegularExpression(
            '/^Requested quantity \(1\) exceeds remaining refundable quantity \(0(\.0+)?\) for line "[^"]+"\.$/',
            $third['error'] ?? '',
        );
    }

    /**
     * Narrower test: after a single partial refund (qty=1 of 3), requesting
     * more than the remaining (qty=3 again) must be rejected with the templated
     * error message. Uses only one prior refund so the serializer bug is not hit.
     */
    public function testExhaustedPoolAfterFirstPartialRefund(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();
        $series = $this->createReceiptSeries($companyId);

        $draft = $this->createSingleLineReceipt($companyId, $series['id'], qty: 3.0);
        $parent = $this->issueReceipt($draft['id'], $companyId);

        $parentDetail = $this->getReceipt($parent['id'], $companyId);
        $sourceLineId = $parentDetail['lines'][0]['id'];

        // Partial refund: qty=1 → succeeds
        $this->apiPost(
            '/api/v1/receipts/' . $parent['id'] . '/refund',
            ['lineSelections' => [['sourceLineId' => $sourceLineId, 'quantity' => '1']]],
            ['X-Company' => $companyId],
        );
        $this->assertResponseStatusCodeSame(201);

        // Requesting qty=3 when only 2 remain → must fail with templated message
        $response = $this->apiPost(
            '/api/v1/receipts/' . $parent['id'] . '/refund',
            ['lineSelections' => [['sourceLineId' => $sourceLineId, 'quantity' => '3']]],
            ['X-Company' => $companyId],
        );
        $this->assertResponseStatusCodeSame(422);
        $this->assertStringContainsString(
            'Requested quantity (3) exceeds remaining refundable quantity (2.0000)',
            $response['error'],
        );
        $this->assertStringContainsString('Test product', $response['error']);
    }

    // -------------------------------------------------------------------------
    // Test 8: Cancelled refund releases quantity pool
    // -------------------------------------------------------------------------

    public function testCancelledRefundReleasesPool(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();
        $series = $this->createReceiptSeries($companyId);

        // Receipt: qty=3
        $draft = $this->createSingleLineReceipt($companyId, $series['id'], qty: 3.0);
        $parent = $this->issueReceipt($draft['id'], $companyId);

        $parentDetail = $this->getReceipt($parent['id'], $companyId);
        $sourceLineId = $parentDetail['lines'][0]['id'];

        // Partial refund: qty=2
        $refund = $this->apiPost(
            '/api/v1/receipts/' . $parent['id'] . '/refund',
            ['lineSelections' => [['sourceLineId' => $sourceLineId, 'quantity' => '2']]],
            ['X-Company' => $companyId],
        );
        $this->assertResponseStatusCodeSame(201);
        $refundId = $refund['id'];

        // Cancel the refund — releases 2 back to the pool
        $cancelled = $this->apiPost('/api/v1/receipts/' . $refundId . '/cancel', [], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);
        $this->assertEquals('cancelled', $cancelled['status']);

        // Now refund the full qty=3 → should succeed because pool was restored
        $refund2 = $this->apiPost(
            '/api/v1/receipts/' . $parent['id'] . '/refund',
            ['lineSelections' => [['sourceLineId' => $sourceLineId, 'quantity' => '3']]],
            ['X-Company' => $companyId],
        );
        $this->assertResponseStatusCodeSame(201);
        $this->assertEqualsWithDelta(-3.0, (float) $refund2['lines'][0]['quantity'], 0.0001);
    }

    // -------------------------------------------------------------------------
    // Test 9: refundedBy JSON omits cancelled refunds
    // -------------------------------------------------------------------------

    public function testRefundedByOmitsCancelledRefunds(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();
        $series = $this->createReceiptSeries($companyId);

        $draft = $this->createSingleLineReceipt($companyId, $series['id']);
        $parent = $this->issueReceipt($draft['id'], $companyId);

        $parentDetail = $this->getReceipt($parent['id'], $companyId);
        $sourceLineId = $parentDetail['lines'][0]['id'];

        // Create a partial refund (qty=1 of 2) so we can cancel it
        $refund = $this->apiPost(
            '/api/v1/receipts/' . $parent['id'] . '/refund',
            ['lineSelections' => [['sourceLineId' => $sourceLineId, 'quantity' => '1']]],
            ['X-Company' => $companyId],
        );
        $this->assertResponseStatusCodeSame(201);
        $refundId = $refund['id'];

        // Cancel the refund
        $this->apiPost('/api/v1/receipts/' . $refundId . '/cancel', [], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);

        // Fetch the parent again — refundedBy must exclude the cancelled refund
        $parentAfter = $this->getReceipt($parent['id'], $companyId);
        $this->assertArrayHasKey('refundedBy', $parentAfter);

        $refundIds = array_column($parentAfter['refundedBy'], 'id');
        $this->assertNotContains($refundId, $refundIds, 'Cancelled refund should not appear in refundedBy.');
    }

    // -------------------------------------------------------------------------
    // Test 10: Invalid line selection (sourceLineId not in parent)
    // -------------------------------------------------------------------------

    public function testInvalidLineSelectionRejected(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();
        $series = $this->createReceiptSeries($companyId);

        $draft = $this->createSingleLineReceipt($companyId, $series['id']);
        $parent = $this->issueReceipt($draft['id'], $companyId);

        // A random UUID that does not belong to this receipt
        $bogusLineId = '00000000-0000-0000-0000-000000000000';

        $response = $this->apiPost(
            '/api/v1/receipts/' . $parent['id'] . '/refund',
            ['lineSelections' => [['sourceLineId' => $bogusLineId, 'quantity' => '1']]],
            ['X-Company' => $companyId],
        );

        $this->assertResponseStatusCodeSame(422);
        $this->assertEquals('Selected line does not belong to this receipt.', $response['error']);
    }

    // -------------------------------------------------------------------------
    // Test 11a: Zero quantity rejected
    // -------------------------------------------------------------------------

    public function testZeroQuantityRejected(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();
        $series = $this->createReceiptSeries($companyId);

        $draft = $this->createSingleLineReceipt($companyId, $series['id']);
        $parent = $this->issueReceipt($draft['id'], $companyId);

        $parentDetail = $this->getReceipt($parent['id'], $companyId);
        $sourceLineId = $parentDetail['lines'][0]['id'];

        $response = $this->apiPost(
            '/api/v1/receipts/' . $parent['id'] . '/refund',
            ['lineSelections' => [['sourceLineId' => $sourceLineId, 'quantity' => '0']]],
            ['X-Company' => $companyId],
        );

        $this->assertResponseStatusCodeSame(422);
        $this->assertEquals('Refund quantity must be positive.', $response['error']);
    }

    // -------------------------------------------------------------------------
    // Test 11b: Negative quantity rejected
    // -------------------------------------------------------------------------

    public function testNegativeQuantityRejected(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();
        $series = $this->createReceiptSeries($companyId);

        $draft = $this->createSingleLineReceipt($companyId, $series['id']);
        $parent = $this->issueReceipt($draft['id'], $companyId);

        $parentDetail = $this->getReceipt($parent['id'], $companyId);
        $sourceLineId = $parentDetail['lines'][0]['id'];

        $response = $this->apiPost(
            '/api/v1/receipts/' . $parent['id'] . '/refund',
            ['lineSelections' => [['sourceLineId' => $sourceLineId, 'quantity' => '-1']]],
            ['X-Company' => $companyId],
        );

        $this->assertResponseStatusCodeSame(422);
        $this->assertEquals('Refund quantity must be positive.', $response['error']);
    }

    // -------------------------------------------------------------------------
    // Test 12: Empty lineSelections array triggers full-refund path
    // -------------------------------------------------------------------------

    public function testEmptySelectionsArrayTriggersFullRefund(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();
        $series = $this->createReceiptSeries($companyId);

        $draft = $this->createSingleLineReceipt($companyId, $series['id'], qty: 3.0);
        $parent = $this->issueReceipt($draft['id'], $companyId);

        // Explicitly pass an empty array — must behave identically to omitting the key
        $refund = $this->apiPost(
            '/api/v1/receipts/' . $parent['id'] . '/refund',
            ['lineSelections' => []],
            ['X-Company' => $companyId],
        );

        $this->assertResponseStatusCodeSame(201);

        // All 3 units should be negated
        $this->assertCount(1, $refund['lines']);
        $this->assertEqualsWithDelta(-3.0, (float) $refund['lines'][0]['quantity'], 0.0001);

        // A second full refund with empty array must now fail (pool exhausted)
        $response = $this->apiPost(
            '/api/v1/receipts/' . $parent['id'] . '/refund',
            ['lineSelections' => []],
            ['X-Company' => $companyId],
        );
        $this->assertResponseStatusCodeSame(422);
        $this->assertEquals('Receipt has already been refunded.', $response['error']);
    }
}
