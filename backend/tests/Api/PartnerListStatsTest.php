<?php

declare(strict_types=1);

namespace App\Tests\Api;

/**
 * The client and supplier lists compute invoice statistics in two ways: for the page ids only
 * (default and name order) or for the whole company (when sorting by a statistic). Both must
 * give the same figures, and the "has invoices" filter must agree with them.
 */
final class PartnerListStatsTest extends ApiTestCase
{
    public function testClientStatsMatchAcrossSortsAndFilters(): void
    {
        $this->login();
        $headers = ['X-Company' => $this->getFirstCompanyId()];
        $tag = 'Stats ' . uniqid();

        $billed = $this->createPartnerClient($tag . ' billed', $headers);
        $this->createPartnerClient($tag . ' idle', $headers);
        $this->issueInvoice($billed, '100.00', '-3 days', $headers);
        $this->issueInvoice($billed, '50.00', 'today', $headers);

        $search = '&search=' . rawurlencode($tag);
        $recent = $this->byId($this->apiGet('/api/v1/clients?limit=20' . $search, $headers));
        self::assertCount(2, $recent);
        self::assertSame(2, $recent[$billed]['invoiceCount']);
        self::assertEqualsWithDelta(150.0, $recent[$billed]['invoiceTotal'], 0.001);
        self::assertSame((new \DateTimeImmutable('today'))->format('Y-m-d'), $recent[$billed]['lastInvoiceDate']);
        $idle = array_values(array_filter($recent, fn ($row) => $row['id'] !== $billed))[0];
        self::assertSame(0, $idle['invoiceCount']);
        self::assertSame(0, (int) $idle['invoiceTotal']);
        self::assertNull($idle['lastInvoiceDate']);

        $sorted = $this->apiGet('/api/v1/clients?limit=20&sort=mostInvoices' . $search, $headers);
        self::assertSame($billed, $sorted['data'][0]['id']);
        self::assertSame($recent, $this->byId($sorted));
        self::assertSame(array_keys($sorted['data'][0]), array_keys($recent[$billed]), 'Both paths return the same keys in the same order');

        $active = $this->apiGet('/api/v1/clients?hasInvoices=active' . $search, $headers);
        self::assertSame(1, $active['total']);
        self::assertSame($billed, $active['data'][0]['id']);
        $dormant = $this->apiGet('/api/v1/clients?hasInvoices=dormant' . $search, $headers);
        self::assertSame(1, $dormant['total']);
        self::assertSame($idle['id'], $dormant['data'][0]['id']);
    }

    public function testCompanyWideClientAndSupplierStatsMatchThePagePath(): void
    {
        $this->login();
        $headers = ['X-Company' => $this->getFirstCompanyId()];

        foreach (['/api/v1/clients', '/api/v1/suppliers'] as $list) {
            $total = $this->apiGet($list, $headers)['total'];
            if ($total > 1500) {
                self::markTestSkipped('The fixture company has too many partners to page through.');
            }
            $recent = $this->allPages($list . '?sort=recent', $headers);
            self::assertCount($total, $recent, $list);
            self::assertSame($recent, $this->allPages($list . '?sort=mostInvoiced', $headers), $list);

            $withInvoices = count(array_filter($recent, fn ($row) => $row['invoiceCount'] > 0));
            self::assertSame($withInvoices, $this->apiGet($list . '?hasInvoices=active', $headers)['total'], $list);
            self::assertSame($total - $withInvoices, $this->apiGet($list . '?hasInvoices=dormant', $headers)['total'], $list);
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function allPages(string $uri, array $headers): array
    {
        $rows = [];
        for ($page = 1; ; ++$page) {
            $response = $this->apiGet($uri . '&page=' . $page, $headers);
            $rows += $this->byId($response);
            if (count($response['data']) < $response['limit'] || $page * $response['limit'] >= $response['total']) {
                break;
            }
        }
        ksort($rows);

        return $rows;
    }

    /** @return array<string, array<string, mixed>> */
    private function byId(array $response): array
    {
        $rows = [];
        foreach ($response['data'] as $row) {
            $rows[$row['id']] = $row;
        }
        ksort($rows);

        return $rows;
    }

    private function createPartnerClient(string $name, array $headers): string
    {
        $client = $this->apiPost('/api/v1/clients', [
            'type' => 'individual',
            'name' => $name,
            'country' => 'RO',
            'county' => 'Bucuresti',
            'city' => 'Bucuresti',
            'address' => 'Str. Test 1',
        ], $headers);
        self::assertSame(201, $this->client->getResponse()->getStatusCode(), json_encode($client));

        return $client['id'] ?? $client['client']['id'];
    }

    private function issueInvoice(string $clientId, string $price, string $issueDate, array $headers): void
    {
        $invoice = $this->apiPost('/api/v1/invoices', [
            'documentType' => 'invoice',
            'issueDate' => (new \DateTimeImmutable($issueDate))->format('Y-m-d'),
            'dueDate' => (new \DateTimeImmutable('+10 days'))->format('Y-m-d'),
            'currency' => 'RON',
            'clientId' => $clientId,
            'lines' => [
                ['description' => 'Servicii', 'quantity' => '1.00', 'unitOfMeasure' => 'buc', 'unitPrice' => $price, 'vatRate' => '0.00', 'vatCategoryCode' => 'Z'],
            ],
        ], $headers);
        self::assertNotNull($invoice['invoice']['id'] ?? $invoice['id'] ?? null, json_encode($invoice));
    }
}
