<?php

namespace App\Tests\Api;

use Symfony\Component\Uid\Uuid;

class FiscalCalendarTest extends ApiTestCase
{
    public function testCompanyCalendarHasTheExpectedShapeAndFollowsTheProfile(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();
        $headers = ['X-Company' => $companyId];

        // A monthly VAT payer with employees and a quarterly income tax
        $this->apiPatch('/api/v1/companies/' . $companyId, ['vatPayer' => true, 'vatPeriod' => 'monthly', 'incomeTaxPeriod' => 'quarterly', 'hasEmployees' => true], $headers);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $company = $this->apiGet('/api/v1/companies/' . $companyId, $headers);
        self::assertSame('monthly', $company['vatPeriod']);
        self::assertSame('quarterly', $company['incomeTaxPeriod']);
        self::assertTrue($company['hasEmployees']);

        $res = $this->apiGet('/api/v1/fiscal-calendar?from=2026-10-01&days=60', $headers);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), json_encode($res));
        self::assertSame('2026-10-01', $res['from']);
        self::assertSame(60, $res['days']);
        self::assertSame($companyId, $res['company']['id']);
        self::assertArrayHasKey('counts', $res);

        $byKey = [];
        foreach ($res['data'] as $item) {
            foreach (['code', 'label', 'dueDate', 'nominalDueDate', 'daysLeft', 'period', 'appliesBecause', 'status'] as $field) {
                self::assertArrayHasKey($field, $item);
            }
            self::assertArrayHasKey('declarationType', $item);
            self::assertContains($item['status'], ['due', 'overdue', 'filed']);
            $byKey[$item['code'] . ' ' . $item['dueDate']] = $item;
        }
        self::assertArrayHasKey('D300 2026-10-26', $byKey, '25 Oct 2026 is a Sunday: ' . implode(', ', array_keys($byKey)));
        self::assertSame(['year' => 2026, 'month' => 9, 'from' => '2026-09-01', 'to' => '2026-09-30'], $byKey['D300 2026-10-26']['period']);
        self::assertSame('d300', $byKey['D300 2026-10-26']['declarationType']);
        self::assertArrayHasKey('D394 2026-10-30', $byKey);
        self::assertArrayHasKey('D112 2026-10-26', $byKey);
        self::assertArrayHasKey('D100 2026-10-26', $byKey);
        self::assertArrayHasKey('D406 2026-11-02', $byKey);
        self::assertArrayNotHasKey('D100 2026-11-25', $byKey, 'quarterly income tax');

        // Quarterly VAT period, no employees: November has no D300 / D112 anymore
        $this->apiPatch('/api/v1/companies/' . $companyId, ['vatPeriod' => 'quarterly', 'hasEmployees' => false], $headers);
        $res = $this->apiGet('/api/v1/fiscal-calendar?from=2026-10-01&days=60', $headers);
        $codes = array_map(static fn (array $i) => $i['code'] . ' ' . $i['dueDate'], $res['data']);
        self::assertContains('D300 2026-10-26', $codes);
        self::assertNotContains('D300 2026-11-25', $codes);
        self::assertNotContains('D112 2026-10-26', $codes);

        // The dates are sorted
        $dates = array_map(static fn (array $i) => $i['dueDate'], $res['data']);
        $sorted = $dates;
        sort($sorted);
        self::assertSame($sorted, $dates);
    }

    public function testFiledDeclarationMarksTheDeadline(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();
        $headers = ['X-Company' => $companyId];
        $this->apiPatch('/api/v1/companies/' . $companyId, ['vatPayer' => true, 'vatPeriod' => 'monthly'], $headers);

        // A draft D300 for September 2026 does not count as filed
        $decl = $this->apiPost('/api/v1/declarations', ['type' => 'd300', 'year' => 2026, 'month' => 9], $headers);
        self::assertSame(201, $this->client->getResponse()->getStatusCode(), json_encode($decl));
        $res = $this->apiGet('/api/v1/fiscal-calendar?from=2026-11-05&days=30', $headers);
        $items = [];
        foreach ($res['data'] as $item) {
            $items[$item['code'] . ' ' . $item['dueDate']] = $item;
        }
        self::assertSame('overdue', $items['D300 2026-10-26']['status'] ?? null, json_encode(array_keys($items)));
        self::assertSame('due', $items['D300 2026-11-25']['status'] ?? null);
        self::assertSame(-10, $items['D300 2026-10-26']['daysLeft']);
    }

    public function testValidationAndCompanyScoping(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $this->apiGet('/api/v1/fiscal-calendar?days=0', ['X-Company' => $companyId]);
        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        $this->apiGet('/api/v1/fiscal-calendar?from=2026-13-01', ['X-Company' => $companyId]);
        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        $this->apiPatch('/api/v1/companies/' . $companyId, ['vatPeriod' => 'yearly'], ['X-Company' => $companyId]);
        self::assertSame(400, $this->client->getResponse()->getStatusCode());

        // A company that is not the caller's is not found
        $this->apiGet('/api/v1/fiscal-calendar', ['X-Company' => Uuid::v7()->toRfc4122()]);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());

        // Every company of the organization
        $all = $this->apiGet('/api/v1/fiscal-calendar/all?from=2026-10-01&days=30');
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), json_encode($all));
        self::assertArrayHasKey('companies', $all);
        self::assertNotEmpty($all['data']);
        foreach ($all['data'] as $item) {
            self::assertArrayHasKey('company', $item);
            self::assertArrayHasKey('id', $item['company']);
        }
        $ids = array_column($all['companies'], 'id');
        self::assertContains($companyId, $ids);
    }
}
