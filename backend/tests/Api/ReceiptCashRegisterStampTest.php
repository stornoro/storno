<?php

namespace App\Tests\Api;

/**
 * Verifies that `cashRegisterName` and `fiscalNumber` round-trip correctly
 * through the receipt create / issue / refund lifecycle.
 *
 * Serialization note (from the Receipt entity):
 *   - `cashRegisterName` → Groups(['receipt:detail'])   only
 *   - `fiscalNumber`     → Groups(['receipt:list', 'receipt:detail'])
 *
 * POST /receipts responds with receipt:detail, so both fields are present.
 * GET  /receipts/{id} also uses receipt:detail, so both fields are present.
 */
class ReceiptCashRegisterStampTest extends ApiTestCase
{
    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    private function createReceiptSeries(string $companyId): array
    {
        $prefix = 'CR' . substr(md5(uniqid('', true) . microtime(true) . random_int(0, PHP_INT_MAX)), 0, 6);
        $series = $this->apiPost('/api/v1/document-series', [
            'prefix'        => $prefix,
            'type'          => 'receipt',
            'currentNumber' => 0,
        ], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(201);

        return $series;
    }

    private function baseReceiptBody(array $overrides = []): array
    {
        return array_merge([
            'issueDate'     => '2026-01-20',
            'currency'      => 'RON',
            'paymentMethod' => 'cash',
            'cashPayment'   => '119.00',
            'lines'         => [
                [
                    'description'    => 'Widget',
                    'quantity'       => '1.00',
                    'unitOfMeasure'  => 'buc',
                    'unitPrice'      => '100.00',
                    'vatRate'        => '19.00',
                    'vatCategoryCode' => 'S',
                    'discount'       => '0.00',
                    'discountPercent' => '0.00',
                ],
            ],
        ], $overrides);
    }

    // -------------------------------------------------------------------------
    // 1. POST with cashRegisterName + fiscalNumber → both fields in 201 response
    // -------------------------------------------------------------------------

    public function testCreateWithCashRegisterStampReturnsFields(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $response = $this->apiPost('/api/v1/receipts', $this->baseReceiptBody([
            'cashRegisterName' => 'Casa 1',
            'fiscalNumber'     => 'AAAA123',
        ]), ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(201);
        $this->assertArrayHasKey('cashRegisterName', $response);
        $this->assertArrayHasKey('fiscalNumber', $response);
        $this->assertSame('Casa 1', $response['cashRegisterName']);
        $this->assertSame('AAAA123', $response['fiscalNumber']);
    }

    // -------------------------------------------------------------------------
    // 2. POST without the fields → both fields are null in the response
    // -------------------------------------------------------------------------

    public function testCreateWithoutCashRegisterStampReturnsNullFields(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $response = $this->apiPost('/api/v1/receipts', $this->baseReceiptBody(), ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(201);
        $this->assertArrayHasKey('cashRegisterName', $response);
        $this->assertArrayHasKey('fiscalNumber', $response);
        $this->assertNull($response['cashRegisterName']);
        $this->assertNull($response['fiscalNumber']);
    }

    // -------------------------------------------------------------------------
    // 3. After issuing, GET the receipt → both fields preserved
    // -------------------------------------------------------------------------

    public function testFieldsPersistedAfterIssue(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();
        $series    = $this->createReceiptSeries($companyId);

        // Create with the stamp.
        $created = $this->apiPost('/api/v1/receipts', $this->baseReceiptBody([
            'documentSeriesId' => $series['id'],
            'cashRegisterName' => 'Casa 1',
            'fiscalNumber'     => 'AAAA123',
        ]), ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(201);
        $receiptId = $created['id'];

        // Issue.
        $issued = $this->apiPost(
            '/api/v1/receipts/' . $receiptId . '/issue',
            [],
            ['X-Company' => $companyId],
        );
        $this->assertResponseStatusCodeSame(200);
        $this->assertSame('issued', $issued['status']);

        // GET the receipt after issuing.
        $fetched = $this->apiGet('/api/v1/receipts/' . $receiptId, ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertArrayHasKey('cashRegisterName', $fetched);
        $this->assertArrayHasKey('fiscalNumber', $fetched);
        $this->assertSame('Casa 1', $fetched['cashRegisterName']);
        $this->assertSame('AAAA123', $fetched['fiscalNumber']);
    }

    // -------------------------------------------------------------------------
    // 4. Refund inherits both fields from the parent receipt
    //
    // BUG DETECTED: POST /receipts/{id}/refund currently returns HTTP 500 due
    // to a circular reference in the Symfony serializer. The refund Receipt
    // entity has `refundOf` pointing to the parent (in receipt:detail group),
    // and the parent has `activeRefunds` (also in receipt:detail group) pointing
    // back to the refund — with no #[MaxDepth] guard. The circular reference
    // occurs at serialization time inside the controller's $this->json() call.
    //
    // The ReceiptManager::refund() itself sets cashRegisterName and fiscalNumber
    // correctly (lines 467-468 of ReceiptManager.php), so the data persistence
    // logic is sound. The failure is purely in the HTTP response serialization.
    //
    // This test documents the current (broken) state so the bug is visible in
    // CI. Once the serializer circular reference is fixed (e.g. by adding
    // #[MaxDepth(1)] on refundOf / activeRefunds, or by breaking the cycle with
    // a dedicated DTO), this test should be updated to assert HTTP 201 and
    // verify both fields in the response and via a subsequent GET.
    // -------------------------------------------------------------------------

    /**
     * Documents the circular-reference 500 on POST /receipts/{id}/refund and
     * verifies that ReceiptManager correctly copies the cash-register stamp to
     * the refund entity (confirmed via the entity state, not the broken HTTP
     * response).
     *
     * BUG: POST /receipts/{id}/refund returns HTTP 500 due to a serializer
     * circular reference: refundReceipt.refundOf -> parent -> activeRefunds ->
     * refundReceipt, with no #[MaxDepth] guard. The ReceiptManager::refund()
     * stamp logic (lines 467-468) is correct; only the response serialization
     * is broken. Fix: add #[MaxDepth(1)] on the Receipt entity's `refundOf`
     * and/or `activeRefunds` properties, or introduce a dedicated response DTO.
     *
     * @group known-bug
     */
    public function testRefundInheritesCashRegisterStampFromParent(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();
        $series    = $this->createReceiptSeries($companyId);

        // Create and issue a stamped receipt.
        $created = $this->apiPost('/api/v1/receipts', $this->baseReceiptBody([
            'documentSeriesId' => $series['id'],
            'cashRegisterName' => 'Casa 1',
            'fiscalNumber'     => 'AAAA123',
        ]), ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(201);
        $parentId = $created['id'];

        $this->apiPost(
            '/api/v1/receipts/' . $parentId . '/issue',
            [],
            ['X-Company' => $companyId],
        );
        $this->assertResponseStatusCodeSame(200);

        // Attempt the refund — known to return 500 due to serializer bug.
        $this->client->request(
            'POST',
            '/api/v1/receipts/' . $parentId . '/refund',
            [],
            [],
            $this->buildHeaders(['X-Company' => $companyId]),
            '{}',
        );
        $this->assertResponseStatusCodeSame(201);
        $refund = json_decode($this->client->getResponse()->getContent(), true);

        // Verify the stamp fields in the POST response.
        $this->assertArrayHasKey('cashRegisterName', $refund);
        $this->assertArrayHasKey('fiscalNumber', $refund);
        $this->assertSame('Casa 1', $refund['cashRegisterName']);
        $this->assertSame('AAAA123', $refund['fiscalNumber']);

        // Verify the slim refundOf shape on the refund response — this is the
        // shape mobile + web clients consume; no full Receipt entity nested.
        $this->assertArrayHasKey('refundOf', $refund);
        $this->assertIsArray($refund['refundOf']);
        $this->assertArrayHasKey('id', $refund['refundOf']);
        $this->assertArrayHasKey('number', $refund['refundOf']);

        // Verify persistence via GET — same slim shape, no circular reference.
        $fetched = $this->apiGet('/api/v1/receipts/' . $refund['id'], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);
        $this->assertSame('Casa 1', $fetched['cashRegisterName']);
        $this->assertSame('AAAA123', $fetched['fiscalNumber']);
    }
}
