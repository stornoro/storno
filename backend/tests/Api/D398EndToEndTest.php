<?php

namespace App\Tests\Api;

/**
 * Builds a D398 (OSS VAT return, regimul UE) for the current quarter from a special-regime
 * sale to a consumer in Germany and runs it through ANAF's own validator (DUKIntegrator via
 * the Java service). Skipped when the Java service is down.
 */
class D398EndToEndTest extends ApiTestCase
{
    public function testCurrentQuarterOssReturnIsPopulatedAndAcceptedByTheAnafValidator(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        // A consumer in another member state
        $client = $this->apiPost('/api/v1/clients', [
            'name' => 'Max Mustermann ' . random_int(1000, 9999),
            'type' => 'individual',
            'country' => 'DE',
            'city' => 'Berlin',
            'address' => 'Musterstrasse 1',
        ], ['X-Company' => $companyId]);
        $clientId = $client['id'] ?? $client['client']['id'] ?? null;
        self::assertNotNull($clientId, json_encode($client));

        $today = new \DateTimeImmutable('today');
        $created = $this->apiPost('/api/v1/invoices', [
            'documentType' => 'invoice',
            'issueDate' => $today->format('Y-m-d'),
            'dueDate' => $today->modify('+14 days')->format('Y-m-d'),
            'currency' => 'EUR',
            'exchangeRate' => '4.9750',
            'clientId' => $clientId,
            'invoiceTypeCode' => 'special_regime_art_314_315',
            'lines' => [
                ['description' => 'Produs vandut la distanta', 'quantity' => '2.00', 'unitOfMeasure' => 'buc', 'unitPrice' => '50.00', 'vatRate' => '0.00', 'vatCategoryCode' => 'O'],
            ],
        ], ['X-Company' => $companyId]);
        $invoiceId = $created['invoice']['id'] ?? $created['id'] ?? null;
        self::assertNotNull($invoiceId, json_encode($created));
        $this->apiPost('/api/v1/invoices/' . $invoiceId . '/issue', [], ['X-Company' => $companyId]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'issue: ' . $this->client->getResponse()->getContent());

        $decl = $this->apiPost('/api/v1/declarations', [
            'type' => 'd398',
            'year' => (int) $today->format('Y'),
            'month' => (int) $today->format('n'),
        ], ['X-Company' => $companyId]);
        self::assertSame(201, $this->client->getResponse()->getStatusCode(), json_encode($decl));
        self::assertSame('quarterly', $decl['periodType'] ?? null);
        $rows = $decl['data']['rows'] ?? [];
        $states = $decl['data']['states'] ?? [];
        self::assertSame('1', $rows['moes_voes_imp']);
        self::assertSame('0', $rows['nil_vat_return']);
        self::assertSame('EUR', $rows['currency']);
        self::assertContains((int) $rows['luna_r'], [3, 6, 9, 12]);
        $de = null;
        foreach ($states as $state) {
            if ($state['mscon_state'] === 'DE') {
                $de = $state;
            }
        }
        self::assertNotNull($de, 'Germany must be a member state of consumption: ' . json_encode($decl['data']));
        $goods = array_values(array_filter($de['supplies'], static fn (array $s) => $s['supply_type'] === '1'));
        self::assertNotEmpty($goods);
        self::assertSame('19', $goods[0]['vat_rate'], 'the German standard rate applies');
        self::assertSame('1', $goods[0]['vat_rate_type']);
        self::assertGreaterThanOrEqual(100.0, (float) $goods[0]['taxable_amount']);
        self::assertSame(number_format(round((float) $goods[0]['taxable_amount'] * 0.19, 2), 2, '.', ''), $goods[0]['vat_amount']);
        self::assertSame($de['grand_total'], $de['due_balance']);
        self::assertGreaterThanOrEqual((float) $de['due_balance'], (float) $rows['grand_total_vat_due']);

        $this->apiGet('/api/v1/declarations/' . $decl['id'] . '/xml', ['X-Company' => $companyId]);
        $xmlBody = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('<d398', $xmlBody);
        self::assertStringContainsString('mscon_state="DE"', $xmlBody);
        self::assertStringContainsString('<SUPPLY', $xmlBody);

        $result = $this->apiPost('/api/v1/declarations/' . $decl['id'] . '/validate', [], ['X-Company' => $companyId]);
        $status = $this->client->getResponse()->getStatusCode();
        $body = $this->client->getResponse()->getContent();
        if ($status >= 500 || str_contains((string) $body, 'Connection refused') || str_contains((string) $body, 'unavailable')) {
            self::markTestSkipped('ANAF validator (Java service) not reachable: ' . substr((string) $body, 0, 200));
        }
        self::assertSame(200, $status, 'DUK validation failed: ' . $body);
    }
}
