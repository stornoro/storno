<?php

namespace App\Tests\Api;

/**
 * The chitanță for a cash payment already recorded on an issued invoice: numbered from the
 * receipt series, dated, printable, never a second payment; and the cash register counts the
 * cash payment on an issued invoice as cash IN.
 */
class PaymentReceiptTest extends ApiTestCase
{
    private function outgoingInvoice(string $companyId): array
    {
        $list = $this->apiGet('/api/v1/invoices?direction=outgoing&limit=50', ['X-Company' => $companyId]);
        foreach ($list['data'] as $inv) {
            if ((float) $inv['total'] > 0 && !in_array($inv['status'], ['cancelled', 'draft'], true)) {
                return $inv;
            }
        }
        $this->fail('No issued outgoing invoice in the fixtures');
    }

    private function receiptSeries(string $companyId): string
    {
        $series = $this->apiGet('/api/v1/document-series', ['X-Company' => $companyId]);
        foreach ($series['data'] ?? $series as $s) {
            if (($s['type'] ?? null) === 'receipt') {
                return $s['id'];
            }
        }
        $created = $this->apiPost('/api/v1/document-series', ['prefix' => 'CHT', 'type' => 'receipt', 'isDefault' => true], ['X-Company' => $companyId]);

        return $created['id'] ?? $created['series']['id'];
    }

    private function ensureCashAccount(string $companyId, string $currency): void
    {
        $accounts = $this->apiGet('/api/v1/bank-accounts', ['X-Company' => $companyId]);
        foreach ($accounts['data'] ?? $accounts as $a) {
            if (($a['type'] ?? null) === 'cash') {
                $this->apiPatch('/api/v1/bank-accounts/' . $a['id'], ['openingBalance' => '0.00', 'openingBalanceDate' => '2026-01-01'], ['X-Company' => $companyId]);

                return;
            }
        }
        $this->apiPost('/api/v1/bank-accounts', ['type' => 'cash', 'currency' => $currency, 'openingBalance' => '0.00', 'openingBalanceDate' => '2026-01-01'], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(201);
    }

    public function testCashPaymentGetsANumberedReceiptAndAPdf(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();
        $invoice = $this->outgoingInvoice($companyId);
        $this->apiPost('/api/v1/invoices/bulk-mark-unpaid', ['ids' => [$invoice['id']]], ['X-Company' => $companyId]);
        $seriesId = $this->receiptSeries($companyId);

        $payment = $this->apiPost('/api/v1/invoices/' . $invoice['id'] . '/payments', [
            'amount' => '10.00',
            'paymentMethod' => 'cash',
            'paymentDate' => '2026-10-23',
        ], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(201);
        $this->assertNull($payment['receiptNumber']);

        $issued = $this->apiPost('/api/v1/invoices/' . $invoice['id'] . '/payments/' . $payment['id'] . '/receipt', [
            'seriesId' => $seriesId,
            'date' => '2026-10-23',
        ], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);
        $this->assertNotEmpty($issued['receiptNumber']);
        $this->assertStringStartsWith('2026-10-23', $issued['receiptDate']);
        $this->assertSame($seriesId, $issued['receiptSeriesId']);

        // Idempotent: the same receipt, no second number
        $again = $this->apiPost('/api/v1/invoices/' . $invoice['id'] . '/payments/' . $payment['id'] . '/receipt', [], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);
        $this->assertSame($issued['receiptNumber'], $again['receiptNumber']);

        // The payment itself is still one payment: amountPaid did not change
        $after = $this->apiGet('/api/v1/invoices/' . $invoice['id'], ['X-Company' => $companyId]);
        $this->assertEquals('10.00', $after['amountPaid']);

        // The receipt number travels with the payment list
        $list = $this->apiGet('/api/v1/invoices/' . $invoice['id'] . '/payments', ['X-Company' => $companyId]);
        $this->assertSame($issued['receiptNumber'], $list[0]['receiptNumber']);

        // PDF
        $this->client->request('GET', '/api/v1/invoices/' . $invoice['id'] . '/payments/' . $payment['id'] . '/receipt/pdf', [], [], $this->buildHeaders(['X-Company' => $companyId]));
        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());

        // Cash register: the cash payment on an issued invoice is cash IN, labelled with the receipt number
        $this->ensureCashAccount($companyId, $invoice['currency']);
        $ledger = $this->apiGet('/api/v1/cash-register/ledger?from=2026-10-23&to=2026-10-23', ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);
        $entries = [];
        foreach ($ledger['days'] as $day) {
            foreach ($day['entries'] as $e) {
                if ($e['kind'] === 'payment' && ($e['paymentId'] ?? null) === $payment['id']) {
                    $entries[] = $e;
                }
            }
        }
        $this->assertCount(1, $entries, 'the payment shows once in the ledger');
        $this->assertSame('chitanta', $entries[0]['documentType']);
        $this->assertSame($issued['receiptNumber'], $entries[0]['documentNumber']);
        $this->assertEquals('10.00', $entries[0]['in']);
        $this->assertEquals('0.00', $entries[0]['out']);

        $this->apiDelete('/api/v1/invoices/' . $invoice['id'] . '/payments/' . $payment['id'], ['X-Company' => $companyId]);
    }

    public function testOnlyCashPaymentsGetAReceipt(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();
        $invoice = $this->outgoingInvoice($companyId);
        $this->apiPost('/api/v1/invoices/bulk-mark-unpaid', ['ids' => [$invoice['id']]], ['X-Company' => $companyId]);

        $payment = $this->apiPost('/api/v1/invoices/' . $invoice['id'] . '/payments', [
            'amount' => '5.00',
            'paymentMethod' => 'bank_transfer',
        ], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(201);

        $this->apiPost('/api/v1/invoices/' . $invoice['id'] . '/payments/' . $payment['id'] . '/receipt', [], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(422);

        $this->client->request('GET', '/api/v1/invoices/' . $invoice['id'] . '/payments/' . $payment['id'] . '/receipt/pdf', [], [], $this->buildHeaders(['X-Company' => $companyId]));
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());

        $this->apiDelete('/api/v1/invoices/' . $invoice['id'] . '/payments/' . $payment['id'], ['X-Company' => $companyId]);
    }
}
