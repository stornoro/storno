<?php

namespace App\Tests\Api;

class ClientStatementTest extends ApiTestCase
{
    /**
     * Creates a client with an e-mail and two issued invoices (one overdue by
     * 45 days, one not yet due). Returns [companyId, clientId].
     */
    private function seedClientWithUnpaidInvoices(string $suffix): array
    {
        $companyId = $this->getFirstCompanyId();
        // Individuals are deduplicated by name, so the name must be unique across runs
        $suffix .= '-' . uniqid();

        // Individual clients: no CUI, so no registry lookup is triggered
        $created = $this->apiPost('/api/v1/clients', [
            'type' => 'individual',
            'name' => 'Client Situatie ' . $suffix,
            'email' => 'situatie-' . strtolower($suffix) . '@example.com',
            'country' => 'RO',
            'county' => 'Bucuresti',
            'city' => 'Bucuresti',
            'address' => 'Str. Test 1',
        ], ['X-Company' => $companyId]);
        self::assertSame(201, $this->client->getResponse()->getStatusCode(), json_encode($created));
        $clientId = $created['id'] ?? $created['client']['id'] ?? null;
        self::assertNotNull($clientId, json_encode($created));

        $today = new \DateTimeImmutable('today');
        foreach ([
            ['issue' => $today->modify('-75 days'), 'due' => $today->modify('-45 days'), 'price' => '1000.00'],
            ['issue' => $today, 'due' => $today->modify('+30 days'), 'price' => '500.00'],
        ] as $spec) {
            $inv = $this->apiPost('/api/v1/invoices', [
                'documentType' => 'invoice',
                'issueDate' => $spec['issue']->format('Y-m-d'),
                'dueDate' => $spec['due']->format('Y-m-d'),
                'currency' => 'RON',
                'clientId' => $clientId,
                'lines' => [
                    ['description' => 'Servicii', 'quantity' => '1.00', 'unitOfMeasure' => 'buc', 'unitPrice' => $spec['price'], 'vatRate' => '0.00', 'vatCategoryCode' => 'Z'],
                ],
            ], ['X-Company' => $companyId]);
            $invoiceId = $inv['invoice']['id'] ?? $inv['id'] ?? null;
            self::assertNotNull($invoiceId, json_encode($inv));
            $this->apiPost('/api/v1/invoices/' . $invoiceId . '/issue', [], ['X-Company' => $companyId]);
            self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'issue: ' . $this->client->getResponse()->getContent());
        }

        return [$companyId, $clientId];
    }

    public function testStatementJsonShapeAndAging(): void
    {
        $this->login();
        [$companyId, $clientId] = $this->seedClientWithUnpaidInvoices('A' . random_int(100, 999));

        $s = $this->apiGet('/api/v1/clients/' . $clientId . '/statement', ['X-Company' => $companyId]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), json_encode($s));

        self::assertSame($clientId, $s['client']['id']);
        self::assertSame((new \DateTimeImmutable('today'))->format('Y-m-d'), $s['asOf']);
        self::assertSame('RON', $s['currency']);
        self::assertCount(2, $s['invoices']);
        self::assertSame('1500.00', $s['balance']);
        self::assertSame('1500.00', $s['totals']['outstanding']);
        self::assertSame('1000.00', $s['totals']['overdue']);
        self::assertSame(2, $s['totals']['count']);

        self::assertSame('500.00', $s['aging']['current']['amount']);
        self::assertSame('1000.00', $s['aging']['days31_60']['amount']);
        self::assertSame(1, $s['aging']['days31_60']['count']);
        foreach (['days1_30', 'days61_90', 'days91_120', 'days121_180', 'over180'] as $band) {
            self::assertSame('0.00', $s['aging'][$band]['amount']);
        }

        $overdue = $s['invoices'][0];
        self::assertSame(45, $overdue['daysOverdue']);
        self::assertSame('days31_60', $overdue['band']);
        foreach (['id', 'number', 'issueDate', 'dueDate', 'total', 'paid', 'outstanding', 'daysOverdue', 'band', 'currency', 'status'] as $key) {
            self::assertArrayHasKey($key, $overdue);
        }
        self::assertArrayHasKey('bankAccounts', $s);

        // asOf before the second invoice: only the first one counts, 15 days overdue
        $asOf = (new \DateTimeImmutable('today'))->modify('-30 days')->format('Y-m-d');
        $past = $this->apiGet('/api/v1/clients/' . $clientId . '/statement?asOf=' . $asOf, ['X-Company' => $companyId]);
        self::assertCount(1, $past['invoices']);
        self::assertSame('1000.00', $past['balance']);
        self::assertSame(15, $past['invoices'][0]['daysOverdue']);
        self::assertSame('days1_30', $past['invoices'][0]['band']);

        $this->apiGet('/api/v1/clients/' . $clientId . '/statement?asOf=not-a-date', ['X-Company' => $companyId]);
        self::assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    public function testStatementPdf(): void
    {
        $this->login();
        [$companyId, $clientId] = $this->seedClientWithUnpaidInvoices('P' . random_int(100, 999));

        $this->client->request('GET', '/api/v1/clients/' . $clientId . '/statement.pdf', [], [], $this->buildHeaders(['X-Company' => $companyId]));
        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode(), $response->getContent());
        self::assertSame('application/pdf', $response->headers->get('Content-Type'));
        self::assertStringStartsWith('%PDF', (string) $response->getContent());
        self::assertStringContainsString('situatie-facturi-', (string) $response->headers->get('Content-Disposition'));
    }

    public function testCompanyStatementsListOnlyClientsWithBalanceSortedDesc(): void
    {
        $this->login();
        [$companyId, $clientId] = $this->seedClientWithUnpaidInvoices('L' . random_int(100, 999));

        $all = $this->apiGet('/api/v1/clients/statements', ['X-Company' => $companyId]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), json_encode($all));
        self::assertArrayHasKey('statements', $all);
        self::assertArrayHasKey('aging', $all);
        self::assertArrayHasKey('totals', $all);
        self::assertSame(\count($all['statements']), $all['clientCount']);

        $found = null;
        $previous = null;
        foreach ($all['statements'] as $st) {
            self::assertGreaterThan(0, (float) $st['balance'], 'only clients with a positive balance are listed');
            if ($previous !== null) {
                self::assertGreaterThanOrEqual((float) $st['balance'], (float) $previous, 'sorted by balance desc');
            }
            $previous = $st['balance'];
            if ($st['client']['id'] === $clientId) {
                $found = $st;
            }
        }
        self::assertNotNull($found, 'the seeded client must be in the list');
        self::assertSame('1500.00', $found['balance']);
        self::assertArrayNotHasKey('bankAccounts', $found);

        $sum = '0.00';
        foreach ($all['aging'] as $band) {
            $sum = bcadd($sum, $band['amount'], 2);
        }
        self::assertSame($all['totals']['outstanding'], $sum);
    }

    public function testBulkEmailDryRunAndSkipLogic(): void
    {
        $this->login();
        [$companyId, $clientId] = $this->seedClientWithUnpaidInvoices('B' . random_int(100, 999));

        // A client with a balance but without e-mail must be skipped
        $noEmail = $this->apiPost('/api/v1/clients', [
            'type' => 'individual',
            'name' => 'Client Fara Email ' . uniqid(),
            'country' => 'RO',
            'county' => 'Bucuresti',
            'city' => 'Bucuresti',
            'address' => 'Str. Test 2',
        ], ['X-Company' => $companyId]);
        $noEmailId = $noEmail['id'] ?? $noEmail['client']['id'] ?? null;
        self::assertNotNull($noEmailId, json_encode($noEmail));
        $inv = $this->apiPost('/api/v1/invoices', [
            'documentType' => 'invoice',
            'issueDate' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
            'dueDate' => (new \DateTimeImmutable('today'))->modify('+10 days')->format('Y-m-d'),
            'currency' => 'RON',
            'clientId' => $noEmailId,
            'lines' => [['description' => 'Servicii', 'quantity' => '1.00', 'unitOfMeasure' => 'buc', 'unitPrice' => '10.00', 'vatRate' => '0.00', 'vatCategoryCode' => 'Z']],
        ], ['X-Company' => $companyId]);
        $this->apiPost('/api/v1/invoices/' . ($inv['invoice']['id'] ?? $inv['id']) . '/issue', [], ['X-Company' => $companyId]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $dry = $this->apiPost('/api/v1/clients/statements/email', ['dryRun' => true], ['X-Company' => $companyId]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), json_encode($dry));
        self::assertTrue($dry['dryRun']);
        self::assertSame('0.01', $dry['minBalance']);
        self::assertSame(0, $dry['failed']);

        $byClient = array_column($dry['results'], null, 'clientId');
        self::assertSame('would_send', $byClient[$clientId]['status']);
        self::assertSame('skipped', $byClient[$noEmailId]['status']);
        self::assertSame('no_email', $byClient[$noEmailId]['reason']);
        self::assertSame($dry['sent'] + $dry['skipped'], \count($dry['results']));

        // minBalance above the seeded client's balance skips it
        $high = $this->apiPost('/api/v1/clients/statements/email', ['dryRun' => true, 'minBalance' => 2000], ['X-Company' => $companyId]);
        $byClient = array_column($high['results'], null, 'clientId');
        self::assertSame('below_min_balance', $byClient[$clientId]['reason']);

        $this->apiPost('/api/v1/clients/statements/email', ['dryRun' => true, 'minBalance' => -1], ['X-Company' => $companyId]);
        self::assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    public function testEmailStatementToClientIsLogged(): void
    {
        $this->login();
        [$companyId, $clientId] = $this->seedClientWithUnpaidInvoices('E' . random_int(100, 999));

        $log = $this->apiPost('/api/v1/clients/' . $clientId . '/statement/email', [
            'message' => 'Va rugam sa efectuati plata in cel mai scurt timp.',
        ], ['X-Company' => $companyId]);
        $status = $this->client->getResponse()->getStatusCode();
        self::assertSame(200, $status, json_encode($log));
        self::assertSame('sent', $log['status']);
        self::assertStringContainsString('Facturi neachitate', $log['subject']);
        self::assertStringStartsWith('situatie-', $log['toEmail']);

        // e-mail override must be a client address of the company
        $this->apiPost('/api/v1/clients/' . $clientId . '/statement/email', ['to' => 'not-an-email'], ['X-Company' => $companyId]);
        self::assertSame(400, $this->client->getResponse()->getStatusCode());

        $blocked = $this->apiPost('/api/v1/clients/' . $clientId . '/statement/email', ['to' => 'stranger-' . random_int(1, 9999) . '@example.org'], ['X-Company' => $companyId]);
        self::assertSame(422, $this->client->getResponse()->getStatusCode(), json_encode($blocked));
        self::assertSame('EMAIL_RECIPIENT_NOT_CLIENT', $blocked['code'] ?? null);

        // bulk send (real) counts the seeded client as sent
        $bulk = $this->apiPost('/api/v1/clients/statements/email', ['minBalance' => 1499], ['X-Company' => $companyId]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), json_encode($bulk));
        $byClient = array_column($bulk['results'], null, 'clientId');
        self::assertSame('sent', $byClient[$clientId]['status'], json_encode($byClient[$clientId]));
        self::assertGreaterThanOrEqual(1, $bulk['sent']);
    }

    public function testStatementRequiresAuthentication(): void
    {
        $this->apiGet('/api/v1/clients/statements');
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }
}
