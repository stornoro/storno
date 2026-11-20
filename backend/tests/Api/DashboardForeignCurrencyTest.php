<?php

declare(strict_types=1);

namespace App\Tests\Api;

/**
 * The "top" widgets join invoices with clients and products, which have a currency column of
 * their own: the rate fallback must name the invoice's column, or the query is ambiguous as
 * soon as the company has an invoice in another currency.
 */
final class DashboardForeignCurrencyTest extends ApiTestCase
{
    public function testTopWidgetsAnswerWhenTheCompanyHasForeignCurrencyInvoices(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();
        $headers = ['X-Company' => $companyId];

        $client = $this->apiPost('/api/v1/clients', [
            'type' => 'individual',
            'name' => 'Client Valuta ' . uniqid(),
            'country' => 'RO',
            'county' => 'Bucuresti',
            'city' => 'Bucuresti',
            'address' => 'Str. Test 1',
        ], $headers);
        self::assertSame(201, $this->client->getResponse()->getStatusCode(), json_encode($client));

        $invoice = $this->apiPost('/api/v1/invoices', [
            'documentType' => 'invoice',
            'issueDate' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
            'dueDate' => (new \DateTimeImmutable('+10 days'))->format('Y-m-d'),
            'currency' => 'EUR',
            'clientId' => $client['id'] ?? $client['client']['id'],
            'lines' => [
                ['description' => 'Servicii', 'quantity' => '1.00', 'unitOfMeasure' => 'buc', 'unitPrice' => '100.00', 'vatRate' => '0.00', 'vatCategoryCode' => 'Z'],
            ],
        ], $headers);
        $invoiceId = $invoice['invoice']['id'] ?? $invoice['id'] ?? null;
        self::assertNotNull($invoiceId, json_encode($invoice));
        $this->apiPost('/api/v1/invoices/' . $invoiceId . '/issue', [], $headers);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());

        // The fallback only names the currency column when a rate is known for it
        $rates = static::getContainer()->get(\App\Service\ExchangeRateService::class);
        $sql = $rates->buildFallbackRateSql(static::getContainer()->get('doctrine')->getConnection(), $companyId, 'RON', 'i.currency');
        if ($sql === '1') {
            self::markTestSkipped('No BNR rate available for the foreign currency: the fallback is a constant and cannot be ambiguous.');
        }
        self::assertStringContainsString('i.currency', $sql);

        foreach (['top-outstanding-clients', 'top-clients-revenue', 'top-products-revenue'] as $widget) {
            $this->apiGet('/api/v1/dashboard/' . $widget, $headers);
            self::assertSame(200, $this->client->getResponse()->getStatusCode(), $widget . ': ' . substr((string) $this->client->getResponse()->getContent(), 0, 300));
        }
    }
}
