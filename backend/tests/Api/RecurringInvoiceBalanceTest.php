<?php

declare(strict_types=1);

namespace App\Tests\Api;

/**
 * A recurring template with a running balance: [[suma:…]] prints computed amounts, [[sold]] the
 * balance left after each issuance, the balance is stored, the last deduction is capped and the
 * template pauses once the balance reaches zero. Constants are used instead of `curs` so the test
 * does not depend on the BNR feed.
 */
class RecurringInvoiceBalanceTest extends ApiTestCase
{
    public function testBalanceDecreasesWithEveryIssuanceAndPausesAtZero(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();
        $h = ['X-Company' => $companyId];

        $ri = $this->apiPost('/api/v1/recurring-invoices', [
            'reference' => 'Chirie cu sold',
            'documentType' => 'invoice',
            'currency' => 'RON',
            'frequency' => 'monthly',
            'frequencyDay' => 20,
            'nextIssuanceDate' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
            'dueDateType' => 'days',
            'dueDateDays' => 15,
            'notes' => 'Reducere chirie (1.900 EUR): [[suma:1900*2]] lei; rata: [[rata]] lei; sold investitie ramas: [[sold]] lei (inainte: [[sold_anterior]]).',
            'balance' => '5000',
            'balanceStep' => '1900*2',
            'lines' => [[
                'description' => 'Chirie [[luna]] [[an]] (reducere [[suma:1900*2]])',
                'quantity' => '1.00', 'unitOfMeasure' => 'buc', 'unitPrice' => '10000.00',
                'vatRate' => '0.00', 'vatCategoryCode' => 'E', 'discount' => '3800.00', 'discountPercent' => '0.00',
            ]],
        ], $h);
        $this->assertResponseStatusCodeSame(201);
        $this->assertSame('5000.00', $ri['balance']);
        $this->assertSame('1900*2', $ri['balanceStep']);

        $first = $this->apiPost('/api/v1/recurring-invoices/' . $ri['id'] . '/issue-now', [], $h);
        $this->assertResponseIsSuccessful();
        $invoice = $this->apiGet('/api/v1/invoices/' . $first['invoiceId'], $h);
        $this->assertStringContainsString('Reducere chirie (1.900 EUR): 3.800,00 lei; rata: 3.800,00 lei; sold investitie ramas: 1.200,00 lei (inainte: 5.000,00).', $invoice['notes']);
        $this->assertStringContainsString('(reducere 3.800,00)', $invoice['lines'][0]['description']);
        $this->assertStringNotContainsString('[[', $invoice['notes']);

        $after = $this->apiGet('/api/v1/recurring-invoices/' . $ri['id'], $h);
        $this->assertSame('1200.00', $after['balance']);
        $this->assertTrue($after['isActive']);

        // the last deduction is capped at what is left and the template pauses
        $second = $this->apiPost('/api/v1/recurring-invoices/' . $ri['id'] . '/issue-now', [], $h);
        $this->assertResponseIsSuccessful();
        $invoice2 = $this->apiGet('/api/v1/invoices/' . $second['invoiceId'], $h);
        $this->assertStringContainsString('rata: 1.200,00 lei; sold investitie ramas: 0,00 lei (inainte: 1.200,00).', $invoice2['notes']);
        $final = $this->apiGet('/api/v1/recurring-invoices/' . $ri['id'], $h);
        $this->assertSame('0.00', $final['balance']);
        $this->assertFalse($final['isActive'], 'a template whose balance reached zero is paused');

        $this->apiDelete('/api/v1/invoices/' . $second['invoiceId'], $h);
        $this->apiDelete('/api/v1/invoices/' . $first['invoiceId'], $h);
        $this->apiDelete('/api/v1/recurring-invoices/' . $ri['id'], $h);
    }

    public function testInvalidBalanceStepIsRejected(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();
        $this->apiPost('/api/v1/recurring-invoices', [
            'documentType' => 'invoice', 'currency' => 'RON', 'frequency' => 'monthly', 'frequencyDay' => 1,
            'nextIssuanceDate' => (new \DateTimeImmutable('today'))->format('Y-m-d'), 'dueDateType' => 'days', 'dueDateDays' => 15,
            'balance' => '100', 'balanceStep' => '1900*usd',
            'lines' => [['description' => 'x', 'quantity' => '1', 'unitOfMeasure' => 'buc', 'unitPrice' => '1', 'vatRate' => '19.00', 'vatCategoryCode' => 'S']],
        ], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(400);
        $this->assertStringContainsString('balanceStep', $this->decodeResponse()['error'] ?? '');
    }
}
