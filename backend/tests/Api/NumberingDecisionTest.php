<?php

namespace App\Tests\Api;

class NumberingDecisionTest extends ApiTestCase
{
    private function seedSeries(string $companyId, string $prefix, string $type, int $currentNumber = 0): array
    {
        $created = $this->apiPost('/api/v1/document-series', [
            'prefix' => $prefix,
            'type' => $type,
            'currentNumber' => $currentNumber,
        ], ['X-Company' => $companyId]);
        self::assertSame(201, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());

        return $created;
    }

    public function testJsonShape(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();
        $prefix = 'ND' . random_int(100, 999);
        $this->seedSeries($companyId, $prefix, 'invoice', 41);

        $data = $this->apiGet('/api/v1/document-series/numbering-decision?year=2026&decisionNumber=3&decisionDate=2026-01-04&responsible=Persoana%20Responsabila&rangeSize=100', ['X-Company' => $companyId]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), json_encode($data));

        self::assertSame(2026, $data['year']);
        self::assertSame(3, $data['decisionNumber']);
        self::assertSame('2026-01-04', $data['decisionDate']);
        self::assertSame('Persoana Responsabila', $data['responsible']);
        self::assertSame(100, $data['rangeSize']);
        self::assertSame('OMFP 2634/2015', $data['legalBasis']['code']);
        self::assertSame($companyId, $data['company']['id']);
        self::assertArrayHasKey('name', $data['company']);
        self::assertArrayHasKey('representative', $data['company']);
        self::assertIsArray($data['warnings']);

        $row = null;
        foreach ($data['rows'] as $r) {
            if ($r['prefix'] === $prefix) {
                $row = $r;
            }
        }
        self::assertNotNull($row, 'the new series is listed');
        self::assertSame('invoice', $row['type']);
        self::assertSame('Factură', $row['typeLabel']);
        self::assertSame(42, $row['firstNumber']);
        self::assertSame(141, $row['lastNumber']);
        self::assertSame($prefix . '0042', $row['firstFormatted']);
        self::assertSame($prefix . '0141', $row['lastFormatted']);
        self::assertSame(0, $row['issuedCount']);
    }

    public function testDefaultsToCurrentYearAndFirstOfJanuary(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $data = $this->apiGet('/api/v1/document-series/numbering-decision', ['X-Company' => $companyId]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame((int) date('Y'), $data['year']);
        self::assertSame(1, $data['decisionNumber']);
        self::assertSame(date('Y') . '-01-01', $data['decisionDate']);
        self::assertSame(9999, $data['rangeSize']);
    }

    public function testInvalidParametersAreRejected(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $this->apiGet('/api/v1/document-series/numbering-decision?year=26', ['X-Company' => $companyId]);
        self::assertSame(400, $this->client->getResponse()->getStatusCode());

        $this->apiGet('/api/v1/document-series/numbering-decision?decisionDate=2026-02-30', ['X-Company' => $companyId]);
        self::assertSame(400, $this->client->getResponse()->getStatusCode());

        $this->apiGet('/api/v1/document-series/numbering-decision?rangeSize=0', ['X-Company' => $companyId]);
        self::assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    public function testPdfBytes(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();
        $this->seedSeries($companyId, 'NP' . random_int(100, 999), 'receipt');

        $this->client->request('GET', '/api/v1/document-series/numbering-decision.pdf?year=2026&decisionNumber=2', [], [], $this->buildHeaders(['X-Company' => $companyId]));
        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode(), $response->getContent());
        self::assertSame('application/pdf', $response->headers->get('Content-Type'));
        self::assertStringStartsWith('%PDF', (string) $response->getContent());
        self::assertStringContainsString('decizie-numerotare-2026-nr-2.pdf', (string) $response->headers->get('Content-Disposition'));
    }

    public function testCompanyScoping(): void
    {
        $this->login();

        // A company the caller does not own (random UUID) is treated as not found.
        $foreign = '00000000-0000-4000-8000-00000000abcd';
        $this->apiGet('/api/v1/document-series/numbering-decision?year=2026', ['X-Company' => $foreign]);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', '/api/v1/document-series/numbering-decision.pdf?year=2026', [], [], $this->buildHeaders(['X-Company' => $foreign]));
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testRequiresAuthentication(): void
    {
        $this->apiGet('/api/v1/document-series/numbering-decision?year=2026');
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }
}
