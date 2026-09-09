<?php

namespace App\Tests\Api;

/** An individual person's dashboard keeps only the widgets about them; the catalog, the default config and PUT follow. */
class DashboardIndividualTest extends ApiTestCase
{
    public function testIndividualDashboardIsTrimmed(): void
    {
        $this->login();
        $person = $this->apiPost('/api/v1/companies', ['type' => 'individual', 'cnp' => '1800101400016', 'name' => 'POPESCU ION', 'city' => 'Sector 6', 'state' => 'Bucuresti']);
        if ($this->client->getResponse()->getStatusCode() === 409) {
            $list = $this->apiGet('/api/v1/companies');
            $person = array_values(array_filter($list['data'], fn ($c) => ($c['type'] ?? '') === 'individual'))[0];
        } elseif ($this->client->getResponse()->getStatusCode() === 402) {
            $this->markTestSkipped('plan limit');
        }
        $h = ['X-Company' => $person['id']];

        $catalog = $this->apiGet('/api/v1/dashboard/widgets/catalog', $h);
        $ids = array_column($catalog['widgets'], 'id');
        $this->assertSame('individual', $catalog['audience']);
        $this->assertContains('dosare-actions', $ids);
        $this->assertContains('amounts-to-pay-card', $ids);
        $this->assertNotContains('sales-card', $ids);
        $this->assertNotContains('top-products-revenue', $ids);

        $config = $this->apiGet('/api/v1/dashboard/config', $h);
        $this->assertSame('dosare-actions', $config['widgets'][0]['id'], 'the dosare feed comes first for a person');
        $this->assertTrue($config['widgets'][0]['visible']);
        $this->assertNotContains('sales-card', array_column($config['widgets'], 'id'));

        $this->apiPut('/api/v1/dashboard/config', ['widgets' => [['id' => 'sales-card', 'position' => 0, 'visible' => true]]], $h);
        $this->assertResponseStatusCodeSame(400);

        $company = $this->apiGet('/api/v1/dashboard/widgets/catalog', ['X-Company' => $this->getFirstCompanyId()]);
        $this->assertSame('company', $company['audience']);
        $this->assertContains('sales-card', array_column($company['widgets'], 'id'));

        $this->apiDelete('/api/v1/companies/' . $person['id']);
    }
}
