<?php

namespace App\Tests\Api;

/**
 * A rental dosar, its tenant as a client, the invoice issued to that client and the declaration
 * attached to the dosar all point at each other through GET /related/{type}/{id}; an individual person
 * (CNP) can be added as a "company" and is what D212 needs.
 */
class RelatedTest extends ApiTestCase
{
    public function testDosarLinksToClientInvoicesAndDeclarations(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();
        $h = ['X-Company' => $companyId];
        $cui = (string) random_int(10000000, 99999999);

        $client = $this->apiPost('/api/v1/clients', ['type' => 'company', 'name' => 'Chirias Test SRL ' . $cui, 'cui' => $cui, 'country' => 'RO', 'city' => 'Bucuresti', 'county' => 'Bucuresti', 'address' => 'Str. Exemplu 1'], $h)['client'];
        $this->assertResponseStatusCodeSame(201);

        // the dosar finds the tenant's client record by CUI on its own
        $dosar = $this->apiPost('/api/v1/dosare', [
            'type' => 'rental_contract',
            'subject' => ['numar' => '9', 'data' => '01.02.2026', 'adresa' => 'Bld. Iuliu Maniu 7', 'chirias' => 'Chirias Test SRL', 'chiriasCif' => 'RO' . $cui, 'chirie' => 1000, 'moneda' => 'RON', 'deLa' => '01.02.2026'],
        ], $h)['dosar'];
        $this->assertResponseStatusCodeSame(201);
        $this->assertSame($client['id'], $dosar['client']['id'] ?? null, 'tenant client auto-linked by CUI');

        $decl = $this->apiPost('/api/v1/declarations', ['type' => 'd300', 'year' => 2026, 'month' => 8, 'dosarId' => $dosar['id']], $h);
        $this->assertResponseStatusCodeSame(201);
        $this->apiPost('/api/v1/dosare/' . $dosar['id'] . '/attach', ['declarationId' => $decl['id']], $h);

        $related = $this->apiGet('/api/v1/related/dosar/' . $dosar['id'], $h);
        $this->assertResponseStatusCodeSame(200);
        $this->assertSame('dosar', $related['source']['type']);
        $this->assertSame($client['id'], $related['groups']['clients'][0]['id']);
        $this->assertSame('/clients/' . $client['id'], $related['groups']['clients'][0]['href']);
        $this->assertSame($decl['id'], $related['groups']['declarations'][0]['id']);

        $fromClient = $this->apiGet('/api/v1/related/client/' . $client['id'], $h);
        $this->assertSame($dosar['id'], $fromClient['groups']['dosare'][0]['id']);
        $this->assertSame($decl['id'], $fromClient['groups']['declarations'][0]['id'], 'declarations reach the client through its dosar');

        $fromDecl = $this->apiGet('/api/v1/related/declaration/' . $decl['id'], $h);
        $this->assertSame($dosar['id'], $fromDecl['groups']['dosare'][0]['id']);
        $this->assertSame($client['id'], $fromDecl['groups']['clients'][0]['id']);

        // explicit unlink / relink through PATCH and the list filter
        $this->apiPatch('/api/v1/dosare/' . $dosar['id'], ['clientId' => null], $h);
        $this->assertNull($this->apiGet('/api/v1/dosare/' . $dosar['id'], $h)['dosar']['client'] ?? null);
        $this->apiPatch('/api/v1/dosare/' . $dosar['id'], ['clientId' => $client['id']], $h);
        $list = $this->apiGet('/api/v1/dosare?clientId=' . $client['id'], $h);
        $this->assertSame([$dosar['id']], array_column($list['data'], 'id'));

        $this->apiGet('/api/v1/related/dosar/' . $client['id'], $h);
        $this->assertResponseStatusCodeSame(404);
        $this->apiGet('/api/v1/related/banana/' . $dosar['id'], $h);
        $this->assertResponseStatusCodeSame(404);

        $this->apiDelete('/api/v1/dosare/' . $dosar['id'], $h);
        $this->apiDelete('/api/v1/clients/' . $client['id'], $h);
    }

    public function testNaturalPersonCompanyWithCnp(): void
    {
        $this->login();

        // a CUI-shaped CNP on the company route is refused with a hint
        $this->apiPost('/api/v1/companies', ['cif' => '1800101400016']);
        $this->assertResponseStatusCodeSame(422);
        $this->assertSame('CNP_NOT_CIF', $this->decodeResponse()['code']);

        $this->apiPost('/api/v1/companies', ['type' => 'individual', 'cnp' => '1800101400017', 'name' => 'POPESCU ION', 'city' => 'Bucuresti', 'state' => 'Bucuresti']);
        $this->assertResponseStatusCodeSame(422);
        $this->assertSame('INVALID_CNP', $this->decodeResponse()['code']);

        $person = $this->apiPost('/api/v1/companies', ['type' => 'individual', 'cnp' => '1800101400016', 'name' => 'POPESCU ION', 'address' => 'Bld. Iuliu Maniu 7', 'city' => 'Sector 6', 'state' => 'Bucuresti']);
        if ($this->client->getResponse()->getStatusCode() === 402) {
            $this->markTestSkipped('plan limit reached for companies in this fixture set');
        }
        $this->assertResponseStatusCodeSame(201);
        $this->assertSame('individual', $person['type']);
        $this->assertTrue($person['isIndividual']);
        $this->assertSame(1800101400016, $person['cif'], 'a 13-digit CNP survives the round trip (BIGINT)');
        $this->assertFalse($person['vatPayer']);
        $h = ['X-Company' => $person['id']];

        $this->apiPost('/api/v1/companies', ['type' => 'individual', 'cnp' => '1800101400016', 'name' => 'POPESCU ION', 'city' => 'Sector 6', 'state' => 'Bucuresti']);
        $this->assertResponseStatusCodeSame(409);

        $this->apiPost('/api/v1/companies/' . $person['id'] . '/refresh-anaf');
        $this->assertResponseStatusCodeSame(400);

        // the person's dosare: the annual return prefill carries the CNP, stats know it is not a company
        $annual = $this->apiPost('/api/v1/dosare/annual-return', [], $h);
        $this->assertResponseStatusCodeSame(200);
        $prefill = $this->apiGet('/api/v1/dosare/' . $annual['dosar']['id'] . '/d212-prefill', $h);
        $this->assertSame('1800101400016', $prefill['input']['contribuabil']['cnp']);
        $this->assertSame('POPESCU ION', $prefill['input']['contribuabil']['nume']);
        $stats = $this->apiGet('/api/v1/dosare/stats', $h);
        $this->assertFalse($stats['landlordIsCompany']);

        $this->apiDelete('/api/v1/companies/' . $person['id']);
    }
}
