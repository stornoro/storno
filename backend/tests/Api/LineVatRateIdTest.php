<?php

namespace App\Tests\Api;

/**
 * A document line can name one of the company's VAT rates by `vatRateId`.
 *
 * The id used to be ignored, so the line fell back to the 21% default without any error. It now
 * stands for the rate's percentage and category, and an id that is not a rate of the company is
 * refused instead of silently becoming 21%.
 */
class LineVatRateIdTest extends ApiTestCase
{
    private function body(array $line): array
    {
        return [
            'documentType' => 'invoice',
            'issueDate' => '2026-02-14',
            'dueDate' => '2026-03-14',
            'currency' => 'RON',
            'lines' => [$line + [
                'description' => 'Sedinta meditatii ' . uniqid(),
                'quantity' => '1.00',
                'unitOfMeasure' => 'buc',
                'unitPrice' => (string) random_int(100, 90000) . '.00',
            ]],
        ];
    }

    /** @var array<int, array{0: string, 1: string}> rates this test added: [companyId, rateId] */
    private array $createdRates = [];

    protected function tearDown(): void
    {
        // Leave the company's rate list as it was: other tests issue invoices against it.
        foreach ($this->createdRates as [$companyId, $rateId]) {
            $this->apiDelete('/api/v1/vat-rates/' . $rateId, ['X-Company' => $companyId]);
        }
        $this->createdRates = [];

        parent::tearDown();
    }

    /**
     * A rate of the company with this percentage, added for the duration of the test when the
     * company has none.
     *
     * @return array{id: string, rate: string, categoryCode: string}
     */
    private function rateOf(string $companyId, string $rate): array
    {
        $rates = $this->apiGet('/api/v1/vat-rates', ['X-Company' => $companyId]);
        $this->assertResponseIsSuccessful();
        foreach ($rates['data'] as $r) {
            if ($r['rate'] === $rate) {
                return $r;
            }
        }

        $created = $this->apiPost('/api/v1/vat-rates', [
            'rate' => $rate,
            'label' => 'Test ' . $rate,
            'categoryCode' => $rate === '0.00' ? 'Z' : 'S',
        ], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(201);
        $created = $created['data'] ?? $created;
        $this->createdRates[] = [$companyId, $created['id']];

        return $created;
    }

    /** @return array{0: string, 1: string} */
    private function twoCompanies(): array
    {
        $companies = $this->apiGet('/api/v1/companies');
        $this->assertGreaterThanOrEqual(2, count($companies['data'] ?? []));

        return [$companies['data'][0]['id'], $companies['data'][1]['id']];
    }

    public function testVatRateIdSetsTheRateAndCategoryOfTheLine(): void
    {
        $this->login();
        [$companyA] = $this->twoCompanies();
        $nine = $this->rateOf($companyA, '9.00');
        $zero = $this->rateOf($companyA, '0.00');

        $data = $this->apiPost('/api/v1/invoices', $this->body(['vatRateId' => $nine['id']]), ['X-Company' => $companyA]);
        $this->assertResponseStatusCodeSame(201);
        $this->assertSame('9.00', $data['invoice']['lines'][0]['vatRate']);
        $this->assertSame('S', $data['invoice']['lines'][0]['vatCategoryCode']);

        $data = $this->apiPost('/api/v1/invoices', $this->body(['vatRateId' => $zero['id']]), ['X-Company' => $companyA]);
        $this->assertResponseStatusCodeSame(201);
        $this->assertSame('0.00', $data['invoice']['lines'][0]['vatRate']);
        $this->assertSame($zero['categoryCode'], $data['invoice']['lines'][0]['vatCategoryCode']);
    }

    public function testExplicitVatRateWinsAndNoVatFieldsKeepTheDefault(): void
    {
        $this->login();
        [$companyA] = $this->twoCompanies();
        $nine = $this->rateOf($companyA, '9.00');

        $data = $this->apiPost('/api/v1/invoices', $this->body(['vatRateId' => $nine['id'], 'vatRate' => '11.00']), ['X-Company' => $companyA]);
        $this->assertResponseStatusCodeSame(201);
        $this->assertSame('11.00', $data['invoice']['lines'][0]['vatRate']);

        $data = $this->apiPost('/api/v1/invoices', $this->body([]), ['X-Company' => $companyA]);
        $this->assertResponseStatusCodeSame(201);
        $this->assertSame('21.00', $data['invoice']['lines'][0]['vatRate']);
    }

    public function testUnknownOrForeignVatRateIdIsRefused(): void
    {
        $this->login();
        [$companyA, $companyB] = $this->twoCompanies();
        $foreign = $this->rateOf($companyB, '9.00');

        $data = $this->apiPost('/api/v1/invoices', $this->body(['vatRateId' => $foreign['id']]), ['X-Company' => $companyA]);
        $this->assertResponseStatusCodeSame(400);
        $this->assertStringContainsString('VAT rate not found', $data['error']);

        $this->apiPost('/api/v1/invoices', $this->body(['vatRateId' => '0193a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b']), ['X-Company' => $companyA]);
        $this->assertResponseStatusCodeSame(400);

        $this->apiPost('/api/v1/invoices', $this->body(['vatRateId' => 'not-a-uuid']), ['X-Company' => $companyA]);
        $this->assertResponseStatusCodeSame(400);
    }
}
