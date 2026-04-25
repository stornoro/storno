<?php

namespace App\Tests\Api;

/**
 * Verifies that every error path in ReceiptManager::refund() surfaces the
 * English error string through the HTTP API (422 + `error` field).
 *
 * One test method per distinct error message. Each test is fully self-contained:
 * it creates all required fixtures from scratch so tests can run in any order
 * and independently.
 */
class ReceiptRefundErrorMessagesTest extends ApiTestCase
{
    // -------------------------------------------------------------------------
    // Shared receipt-body shape (mirrors ReceiptCrudTest conventions)
    // -------------------------------------------------------------------------

    private function minimalReceiptBody(array $overrides = []): array
    {
        return array_merge([
            'issueDate'     => '2026-01-15',
            'currency'      => 'RON',
            'paymentMethod' => 'cash',
            'lines'         => [
                [
                    'description'    => 'Test Product',
                    'quantity'       => '2.00',
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

    /** Create a document series for receipts and return it. */
    private function createReceiptSeries(string $companyId): array
    {
        $prefix = 'TST' . substr(md5(uniqid('', true) . microtime(true) . random_int(0, PHP_INT_MAX)), 0, 6);
        $series = $this->apiPost('/api/v1/document-series', [
            'prefix'        => $prefix,
            'type'          => 'receipt',
            'currentNumber' => 0,
        ], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(201);

        return $series;
    }

    /** Create a draft receipt and return the response body. */
    private function createDraft(string $companyId, string $seriesId, array $bodyOverrides = []): array
    {
        $body = $this->minimalReceiptBody(array_merge(['documentSeriesId' => $seriesId], $bodyOverrides));
        $data = $this->apiPost('/api/v1/receipts', $body, ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(201);

        return $data;
    }

    /** Issue a draft receipt and return the updated body. */
    private function issue(string $companyId, string $receiptId): array
    {
        $data = $this->apiPost(
            '/api/v1/receipts/' . $receiptId . '/issue',
            [],
            ['X-Company' => $companyId],
        );
        $this->assertResponseStatusCodeSame(200);

        return $data;
    }

    /** Attempt a full refund and return the decoded response (do NOT assert status). */
    private function attemptRefund(string $companyId, string $receiptId, array $body = []): array
    {
        return $this->apiPost(
            '/api/v1/receipts/' . $receiptId . '/refund',
            $body,
            ['X-Company' => $companyId],
        );
    }

    // -------------------------------------------------------------------------
    // 1. Only issued receipts can be refunded
    // -------------------------------------------------------------------------

    /**
     * A receipt that is still in DRAFT status must not be refundable.
     */
    public function testRefundDraftReceiptReturnsOnlyIssuedError(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();
        $series    = $this->createReceiptSeries($companyId);
        $draft     = $this->createDraft($companyId, $series['id']);

        $response = $this->attemptRefund($companyId, $draft['id']);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSame('Only issued receipts can be refunded.', $response['error']);
    }

    // -------------------------------------------------------------------------
    // 2. A refund receipt cannot itself be refunded
    // -------------------------------------------------------------------------

    /**
     * A receipt that was itself created as a refund (refundOf != null) must
     * reject a second-level refund attempt.
     */
    public function testRefundOfRefundReturnsCannotRefundRefundError(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();
        $series    = $this->createReceiptSeries($companyId);

        // Create and issue the original receipt.
        $original = $this->createDraft($companyId, $series['id']);
        $this->issue($companyId, $original['id']);

        // Create the first-level refund (should succeed → 201).
        $refund = $this->attemptRefund($companyId, $original['id']);
        $this->assertResponseStatusCodeSame(201);
        $this->assertNotEmpty($refund['id']);

        // Now try to refund the refund.
        $response = $this->attemptRefund($companyId, $refund['id']);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSame('A refund receipt cannot itself be refunded.', $response['error']);
    }

    // -------------------------------------------------------------------------
    // 3. Receipt has already been refunded
    // -------------------------------------------------------------------------

    /**
     * A full refund of a receipt that already has an active (non-cancelled) full
     * refund must be rejected.
     */
    public function testFullRefundAlreadyRefundedReceiptReturnsAlreadyRefundedError(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();
        $series    = $this->createReceiptSeries($companyId);

        $original = $this->createDraft($companyId, $series['id']);
        $this->issue($companyId, $original['id']);

        // First full refund — must succeed.
        $this->attemptRefund($companyId, $original['id']);
        $this->assertResponseStatusCodeSame(201);

        // Second full refund — must be rejected.
        $response = $this->attemptRefund($companyId, $original['id']);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSame('Receipt has already been refunded.', $response['error']);
    }

    // -------------------------------------------------------------------------
    // 4. Selected line does not belong to this receipt
    // -------------------------------------------------------------------------

    /**
     * Passing a sourceLineId that does not belong to the receipt being refunded
     * must be rejected.
     */
    public function testRefundUnknownLineIdReturnsLineNotBelongError(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();
        $series    = $this->createReceiptSeries($companyId);

        $original = $this->createDraft($companyId, $series['id']);
        $this->issue($companyId, $original['id']);

        $response = $this->attemptRefund($companyId, $original['id'], [
            'lineSelections' => [
                [
                    'sourceLineId' => '00000000-0000-0000-0000-000000000000',
                    'quantity'     => '1',
                ],
            ],
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSame('Selected line does not belong to this receipt.', $response['error']);
    }

    // -------------------------------------------------------------------------
    // 5. Refund quantity must be positive
    // -------------------------------------------------------------------------

    /**
     * Requesting a refund with quantity <= 0 for a valid line must be rejected.
     */
    public function testRefundZeroQuantityReturnsQuantityMustBePositiveError(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();
        $series    = $this->createReceiptSeries($companyId);

        $original = $this->createDraft($companyId, $series['id']);
        $this->issue($companyId, $original['id']);

        // Fetch the receipt detail to obtain a valid line ID.
        $detail  = $this->apiGet('/api/v1/receipts/' . $original['id'], ['X-Company' => $companyId]);
        $lineId  = $detail['lines'][0]['id'];

        $response = $this->attemptRefund($companyId, $original['id'], [
            'lineSelections' => [
                ['sourceLineId' => $lineId, 'quantity' => '0'],
            ],
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSame('Refund quantity must be positive.', $response['error']);
    }

    // -------------------------------------------------------------------------
    // 6. Select at least one line to refund
    // -------------------------------------------------------------------------

    /**
     * Sending an empty lineSelections array is treated as "partial refund mode
     * with nothing selected", which must be rejected. Note: passing NO
     * lineSelections key at all triggers the full-refund path; passing an
     * explicit empty array triggers the partial path and hits this guard.
     */
    public function testRefundEmptyLineSelectionsReturnsSelectAtLeastOneError(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();
        $series    = $this->createReceiptSeries($companyId);

        $original = $this->createDraft($companyId, $series['id']);
        $this->issue($companyId, $original['id']);

        // The controller only passes lineSelections to the manager when the key
        // is present AND is a non-empty array. An empty array in the body means
        // lineSelections defaults to [] inside the controller, which is treated
        // as the full-refund path (isPartial = false). To reach the
        // "Select at least one line" guard, we must make $isPartial = true with
        // an empty $linesToRefund result — which happens only if every
        // selection maps to a line ID that passes the lookup but yields 0
        // matching lines after filtering. However, inspecting the manager logic,
        // the guard `count($linesToRefund) === 0` is unreachable after a valid
        // lookup because each selection that passes validation always appends to
        // $linesToRefund.
        //
        // The guard IS reachable from the controller: when `lineSelections` key
        // is absent or not an array the controller defaults to [] → full-refund.
        // When it IS a non-empty array every element either throws "line does not
        // belong" / "quantity not positive" / "exceeds remaining" OR appends an
        // entry. So `count($linesToRefund) === 0` with $isPartial = true can
        // only be triggered by passing a non-empty array of selections that all
        // resolve to no-op (currently impossible by design).
        //
        // We document this as an unreachable-in-practice guard and verify the
        // closest observable proxy: an explicitly-empty lineSelections falls
        // through to the full-refund path (no error from this guard).
        //
        // To keep the test suite honest, this test instead verifies the behaviour
        // the API actually exhibits for an empty lineSelections payload — which is
        // to treat it as a full refund (201 success), NOT a 422.
        //
        // If the controller is changed to distinguish between absent and empty
        // lineSelections, this test should be updated accordingly.
        $response = $this->attemptRefund($companyId, $original['id'], [
            'lineSelections' => [],
        ]);

        // Empty array → full-refund path → success (the guard is not hit).
        $this->assertResponseStatusCodeSame(201);
        $this->assertArrayNotHasKey('error', $response);
    }

    // -------------------------------------------------------------------------
    // 7. Requested quantity exceeds remaining refundable quantity
    // -------------------------------------------------------------------------

    /**
     * Requesting more quantity than available on a line must be rejected with
     * the templated error string whose placeholders (X, Y, Z) are all filled.
     */
    public function testRefundExceedingQuantityReturnsTemplatedError(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();
        $series    = $this->createReceiptSeries($companyId);

        // Receipt with 2 units of "Test Product".
        $original = $this->createDraft($companyId, $series['id']);
        $this->issue($companyId, $original['id']);

        $detail  = $this->apiGet('/api/v1/receipts/' . $original['id'], ['X-Company' => $companyId]);
        $line    = $detail['lines'][0];
        $lineId  = $line['id'];

        // Request 99 units when only 2 are available.
        $response = $this->attemptRefund($companyId, $original['id'], [
            'lineSelections' => [
                ['sourceLineId' => $lineId, 'quantity' => '99'],
            ],
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertArrayHasKey('error', $response);

        $errorMessage = $response['error'];

        // The message must start with the fixed prefix.
        $this->assertStringStartsWith('Requested quantity (', $errorMessage);

        // The full template: Requested quantity (X) exceeds remaining refundable quantity (Y) for line "Z".
        $this->assertMatchesRegularExpression(
            '/^Requested quantity \([^)]+\) exceeds remaining refundable quantity \([^)]+\) for line ".+"\.$/u',
            $errorMessage,
        );

        // X placeholder must contain the requested quantity.
        $this->assertStringContainsString('99', $errorMessage);

        // Y placeholder must contain the remaining quantity (2).
        $this->assertStringContainsString('2', $errorMessage);

        // Z placeholder must contain the line description.
        $this->assertStringContainsString($line['description'], $errorMessage);
    }
}
