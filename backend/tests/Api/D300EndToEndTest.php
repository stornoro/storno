<?php

namespace App\Tests\Api;

/**
 * Builds a D300 for the current month from real invoices and runs it through ANAF's own
 * validator (DUKIntegrator via the Java service). Skipped when the Java service is down.
 */
class D300EndToEndTest extends ApiTestCase
{
    public function testCurrentMonthDecontIsPopulatedAndAcceptedByTheAnafValidator(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        // The decont header needs the declarant, the CAEN code and a bank account
        $this->apiPatch('/api/v1/companies/' . $companyId, ['representative' => 'Popescu Ion', 'representativeRole' => 'Administrator', 'caenCode' => '6201'], ['X-Company' => $companyId]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'company update: ' . $this->client->getResponse()->getContent());

        $clients = $this->apiGet('/api/v1/clients', ['X-Company' => $companyId]);
        $clientId = ($clients['data'] ?? $clients['items'] ?? $clients)[0]['id'] ?? null;
        self::assertNotNull($clientId, 'fixtures must provide a client');

        $today = new \DateTimeImmutable('today');
        $created = $this->apiPost('/api/v1/invoices', [
            'documentType' => 'invoice',
            'issueDate' => $today->format('Y-m-d'),
            'dueDate' => $today->modify('+30 days')->format('Y-m-d'),
            'currency' => 'RON',
            'clientId' => $clientId,
            'lines' => [
                ['description' => 'Servicii consultanta', 'quantity' => '1.00', 'unitOfMeasure' => 'buc', 'unitPrice' => '1000.00', 'vatRate' => '21.00', 'vatCategoryCode' => 'S'],
                ['description' => 'Chirie scutita', 'quantity' => '1.00', 'unitOfMeasure' => 'buc', 'unitPrice' => '100.00', 'vatRate' => '0.00', 'vatCategoryCode' => 'E'],
            ],
        ], ['X-Company' => $companyId]);
        $invoiceId = $created['invoice']['id'] ?? $created['id'] ?? null;
        self::assertNotNull($invoiceId, json_encode($created));
        $this->apiPost('/api/v1/invoices/' . $invoiceId . '/issue', [], ['X-Company' => $companyId]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'issue: ' . $this->client->getResponse()->getContent());

        $decl = $this->apiPost('/api/v1/declarations', [
            'type' => 'd300',
            'year' => (int) $today->format('Y'),
            'month' => (int) $today->format('n'),
        ], ['X-Company' => $companyId]);
        self::assertSame(201, $this->client->getResponse()->getStatusCode(), json_encode($decl));
        $rows = $decl['data']['rows'] ?? [];
        self::assertSame('v2026', $decl['data']['layout'] ?? null);
        self::assertGreaterThanOrEqual(1000, (int) ($rows['R9_1'] ?? 0), 'rd.9 base must include the 21% line');
        self::assertGreaterThanOrEqual(210, (int) ($rows['R9_2'] ?? 0));
        self::assertGreaterThanOrEqual(100, (int) ($rows['R15_1'] ?? 0), 'rd.15 base must include the exempt line');
        self::assertSame((int) $rows['R17_2'], (int) $rows['R9_2'] + (int) ($rows['R10_2'] ?? 0) + (int) ($rows['R16_2'] ?? 0) + (int) ($rows['R5_2'] ?? 0) + (int) ($rows['R7_2'] ?? 0) + (int) ($rows['R12_2'] ?? 0) + (int) ($rows['R64_2'] ?? 0) + (int) ($rows['R65_2'] ?? 0) + (int) ($rows['R6_2'] ?? 0) + (int) ($rows['R8_2'] ?? 0) + (int) ($rows['R11_2'] ?? 0), 'rd.19 must be the sum of the collected rows');
        self::assertSame((int) $rows['R41_2'] - (int) $rows['R42_2'], (int) $rows['R37_2'] - (int) $rows['R40_2']);

        $this->apiGet('/api/v1/declarations/' . $decl['id'] . '/xml', ['X-Company' => $companyId]);
        $xmlBody = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('declaratie300', $xmlBody);
        self::assertStringContainsString('R9_1="', $xmlBody);
        self::assertStringNotContainsString('R13_2', $xmlBody);

        $result = $this->apiPost('/api/v1/declarations/' . $decl['id'] . '/validate', [], ['X-Company' => $companyId]);
        $status = $this->client->getResponse()->getStatusCode();
        $body = $this->client->getResponse()->getContent();
        if ($status >= 500 || str_contains((string) $body, 'Connection refused') || str_contains((string) $body, 'unavailable')) {
            self::markTestSkipped('ANAF validator (Java service) not reachable: ' . substr((string) $body, 0, 200));
        }
        self::assertSame(200, $status, 'DUK validation failed: ' . $body);
    }
}
