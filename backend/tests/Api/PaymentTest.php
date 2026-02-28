<?php

namespace App\Tests\Api;

class PaymentTest extends ApiTestCase
{
    private function getFirstInvoiceId(): array
    {
        $companyId = $this->getFirstCompanyId();
        $list = $this->apiGet('/api/v1/invoices', ['X-Company' => $companyId]);
        $this->assertNotEmpty($list['data']);

        return [$companyId, $list['data'][0]['id'], $list['data'][0]['total']];
    }

    public function testRecordPayment(): void
    {
        $this->login();
        [$companyId, $invoiceId, $total] = $this->getFirstInvoiceId();

        // First clear any existing payments
        $this->apiPatch('/api/v1/invoices/' . $invoiceId . '/payment', ['paid' => false], ['X-Company' => $companyId]);

        $data = $this->apiPost('/api/v1/invoices/' . $invoiceId . '/payments', [
            'amount' => '100.00',
            'paymentMethod' => 'bank_transfer',
            'reference' => 'OP-001',
        ], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(201);
        $this->assertEquals('100.00', $data['amount']);
        $this->assertEquals('bank_transfer', $data['paymentMethod']);
        $this->assertEquals('OP-001', $data['reference']);
    }

    public function testPartialPaymentUpdatesInvoice(): void
    {
        $this->login();
        [$companyId, $invoiceId, $total] = $this->getFirstInvoiceId();

        // Clear payments
        $this->apiPatch('/api/v1/invoices/' . $invoiceId . '/payment', ['paid' => false], ['X-Company' => $companyId]);

        // Record partial payment
        $this->apiPost('/api/v1/invoices/' . $invoiceId . '/payments', [
            'amount' => '50.00',
            'paymentMethod' => 'cash',
        ], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(201);

        // Check invoice reflects partial payment
        $invoice = $this->apiGet('/api/v1/invoices/' . $invoiceId, ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);
        $this->assertEquals('50.00', $invoice['amountPaid']);
        $this->assertNull($invoice['paidAt']);
    }

    public function testFullPaymentMarksInvoicePaid(): void
    {
        $this->login();
        [$companyId, $invoiceId, $total] = $this->getFirstInvoiceId();

        // Clear ALL payments first
        $this->apiPatch('/api/v1/invoices/' . $invoiceId . '/payment', ['paid' => false], ['X-Company' => $companyId]);

        // Re-fetch to get the correct balance after clearing
        $invoice = $this->apiGet('/api/v1/invoices/' . $invoiceId, ['X-Company' => $companyId]);
        $balance = $invoice['balance'] ?? $total;

        // Record full payment using balance
        $this->apiPost('/api/v1/invoices/' . $invoiceId . '/payments', [
            'amount' => $balance,
            'paymentMethod' => 'bank_transfer',
        ], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(201);

        // Check invoice is marked as paid
        $invoice = $this->apiGet('/api/v1/invoices/' . $invoiceId, ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);
        $this->assertEquals($total, $invoice['amountPaid']);
        $this->assertNotNull($invoice['paidAt']);
        $this->assertEquals('0.00', $invoice['balance']);
    }

    public function testListPayments(): void
    {
        $this->login();
        [$companyId, $invoiceId] = $this->getFirstInvoiceId();

        // Clear and add a payment
        $this->apiPatch('/api/v1/invoices/' . $invoiceId . '/payment', ['paid' => false], ['X-Company' => $companyId]);
        $this->apiPost('/api/v1/invoices/' . $invoiceId . '/payments', [
            'amount' => '25.00',
            'paymentMethod' => 'card',
        ], ['X-Company' => $companyId]);

        $payments = $this->apiGet('/api/v1/invoices/' . $invoiceId . '/payments', ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);
        $this->assertNotEmpty($payments);
        $this->assertEquals('25.00', $payments[0]['amount']);
    }

    public function testDeletePayment(): void
    {
        $this->login();
        [$companyId, $invoiceId] = $this->getFirstInvoiceId();

        // Clear and add a payment
        $this->apiPatch('/api/v1/invoices/' . $invoiceId . '/payment', ['paid' => false], ['X-Company' => $companyId]);
        $payment = $this->apiPost('/api/v1/invoices/' . $invoiceId . '/payments', [
            'amount' => '30.00',
            'paymentMethod' => 'cash',
        ], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(201);

        // Delete the payment
        $this->apiDelete('/api/v1/invoices/' . $invoiceId . '/payments/' . $payment['id'], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(204);

        // Verify invoice reverted
        $invoice = $this->apiGet('/api/v1/invoices/' . $invoiceId, ['X-Company' => $companyId]);
        $this->assertEquals('0.00', $invoice['amountPaid']);
    }

    public function testOverpaymentRejected(): void
    {
        $this->login();
        [$companyId, $invoiceId, $total] = $this->getFirstInvoiceId();

        // Clear payments
        $this->apiPatch('/api/v1/invoices/' . $invoiceId . '/payment', ['paid' => false], ['X-Company' => $companyId]);

        // Try to pay more than total
        $overpayment = bcadd($total, '100.00', 2);
        $this->apiPost('/api/v1/invoices/' . $invoiceId . '/payments', [
            'amount' => $overpayment,
            'paymentMethod' => 'bank_transfer',
        ], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(422);
    }

    public function testValidationMissingAmount(): void
    {
        $this->login();
        [$companyId, $invoiceId] = $this->getFirstInvoiceId();

        $this->apiPost('/api/v1/invoices/' . $invoiceId . '/payments', [
            'paymentMethod' => 'bank_transfer',
        ], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(400);
    }

    public function testValidationMissingMethod(): void
    {
        $this->login();
        [$companyId, $invoiceId] = $this->getFirstInvoiceId();

        $this->apiPost('/api/v1/invoices/' . $invoiceId . '/payments', [
            'amount' => '10.00',
        ], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(400);
    }

    public function testDashboardPaymentStats(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $data = $this->apiGet('/api/v1/dashboard/stats', ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);
        $this->assertArrayHasKey('payments', $data);
        $this->assertArrayHasKey('outstandingCount', $data['payments']);
        $this->assertArrayHasKey('outstandingAmount', $data['payments']);
        $this->assertArrayHasKey('overdueCount', $data['payments']);
        $this->assertArrayHasKey('overdueAmount', $data['payments']);
    }
}
