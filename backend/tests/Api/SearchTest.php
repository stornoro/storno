<?php

namespace App\Tests\Api;

/**
 * The list search boxes and GET /search find a record by any word of the name, in any order, and
 * by the tax id however it was typed (with the RO prefix, with a space after it, with spaces
 * around the text).
 */
class SearchTest extends ApiTestCase
{
    private string $companyId;
    private string $cui;
    private string $marker;
    private string $clientId;
    private string $invoiceId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->login();
        $this->companyId = $this->getFirstCompanyId();
        $this->cui = (string) random_int(10000000, 99999999);
        $this->marker = 'Zq' . substr(md5(uniqid('', true)), 0, 8);

        $client = $this->apiPost('/api/v1/clients', [
            'name' => 'Cautare ' . $this->marker . ' Exemplu SRL',
            'type' => 'company',
            'cui' => 'RO' . $this->cui,
            'address' => 'Str. Exemplu 1',
            'city' => 'Bucuresti',
            'county' => 'B',
            'country' => 'RO',
        ], $this->headers());
        $this->assertResponseStatusCodeSame(201);
        $this->clientId = $client['client']['id'];

        $issueDate = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $invoice = $this->apiPost('/api/v1/invoices', [
            'documentType' => 'invoice',
            'issueDate' => $issueDate,
            'dueDate' => $issueDate,
            'currency' => 'RON',
            'clientId' => $this->clientId,
            'lines' => [[
                'description' => 'Servicii',
                'quantity' => '1.00',
                'unitOfMeasure' => 'buc',
                'unitPrice' => '100.00',
                'vatRate' => '21.00',
                'vatCategoryCode' => 'S',
            ]],
        ], $this->headers());
        $this->assertResponseStatusCodeSame(201);
        $this->invoiceId = ($invoice['invoice'] ?? $invoice)['id'];
    }

    private function headers(): array
    {
        return ['X-Company' => $this->companyId];
    }

    /** @return list<string> ids on the first page of a list filtered by the search text */
    private function listIds(string $path, string $search): array
    {
        $data = $this->apiGet($path . '?limit=100&search=' . rawurlencode($search), $this->headers());
        $this->assertResponseStatusCodeSame(200);

        return array_column($data['data'], 'id');
    }

    public static function queries(): array
    {
        return [
            'tax id' => ['{cui}'],
            'tax id with the RO prefix' => ['RO{cui}'],
            'lower-case prefix and a space' => ['ro {cui}'],
            'spaces around the text' => ['  {cui}  '],
            'one word of the name' => ['{marker}'],
            'words in another order' => ['exemplu {marker}'],
            'name and tax id together' => ['{marker} RO{cui}'],
        ];
    }

    private function fill(string $template): string
    {
        return strtr($template, ['{cui}' => $this->cui, '{marker}' => $this->marker]);
    }

    /** @dataProvider queries */
    public function testInvoiceListFindsTheInvoiceByItsClient(string $template): void
    {
        $this->assertContains($this->invoiceId, $this->listIds('/api/v1/invoices', $this->fill($template)));
    }

    /** @dataProvider queries */
    public function testClientListFindsTheClient(string $template): void
    {
        $this->assertContains($this->clientId, $this->listIds('/api/v1/clients', $this->fill($template)));
    }

    public function testEveryWordHasToMatch(): void
    {
        $this->assertNotContains($this->invoiceId, $this->listIds('/api/v1/invoices', $this->marker . ' nupotriveste'));
        $this->assertNotContains($this->clientId, $this->listIds('/api/v1/clients', $this->marker . ' nupotriveste'));
    }

    public function testWildcardCharactersMatchAsTyped(): void
    {
        $this->assertSame([], $this->listIds('/api/v1/clients', '%'));
        $this->assertNotContains($this->clientId, $this->listIds('/api/v1/clients', $this->marker . '_'));
    }

    public function testGlobalSearchGroupsInvoiceAndClient(): void
    {
        $data = $this->apiGet('/api/v1/search?q=' . rawurlencode('RO' . $this->cui), $this->headers());
        $this->assertResponseStatusCodeSame(200);

        $this->assertSame(['invoices', 'proformas', 'clients', 'suppliers', 'products'], array_keys($data['groups']));
        $this->assertSame([$this->invoiceId], array_column($data['groups']['invoices'], 'id'));
        $this->assertSame([$this->clientId], array_column($data['groups']['clients'], 'id'));
        $this->assertSame('/clients/' . $this->clientId, $data['groups']['clients'][0]['href']);
        $this->assertSame('/invoices/' . $this->invoiceId, $data['groups']['invoices'][0]['href']);
        $this->assertStringContainsString($this->marker, $data['groups']['invoices'][0]['subtitle']);
        $this->assertSame(2, $data['total']);
    }

    public function testGlobalSearchLimitsTypesAndIgnoresShortQueries(): void
    {
        $only = $this->apiGet('/api/v1/search?types=clients&q=' . $this->marker, $this->headers());
        $this->assertSame(['clients'], array_keys($only['groups']));
        $this->assertSame([$this->clientId], array_column($only['groups']['clients'], 'id'));

        $short = $this->apiGet('/api/v1/search?q=a', $this->headers());
        $this->assertResponseStatusCodeSame(200);
        $this->assertSame(0, $short['total']);
    }
}
