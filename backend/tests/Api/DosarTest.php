<?php

declare(strict_types=1);

namespace App\Tests\Api;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Dosare: create a rental-contract dosar with its 30-day C168 deadline, attach a
 * declaration and a request, the actions feed, the Declarația unică dosar with its
 * 25 May deadline and the D212 prefill from the rental contracts, and the inbox
 * sync linking a recipisa to the declaration's dosar by upload index.
 */
class DosarTest extends ApiTestCase
{
    public function testRentalDosarDeclarationsAndActions(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();
        $h = ['X-Company' => $companyId];

        $created = $this->apiPost('/api/v1/dosare', [
            'type' => 'rental_contract',
            'subject' => ['numar' => '7', 'data' => (new \DateTimeImmutable('-20 days'))->format('d.m.Y'), 'adresa' => 'Bld. Iuliu Maniu 7, ap. 16', 'chirias' => 'IONESCU MARIA', 'chirie' => 2000, 'moneda' => 'RON', 'deLa' => '01.01.2025'],
        ], $h);
        $this->assertResponseStatusCodeSame(201);
        $dosar = $created['dosar'];
        $this->assertSame('Contract de închiriere Bld. Iuliu Maniu 7, ap. 16', $dosar['title']);
        $this->assertSame(10, $dosar['daysToDeadline'], 'C168 must be filed within 30 days of the contract date');
        $this->assertStringContainsString('C168', $dosar['deadlineLabel']);

        // a declaration attached to the dosar; its recipisa follows by upload index
        $decl = $this->apiPost('/api/v1/declarations', ['type' => 'd300', 'year' => 2026, 'month' => 7], $h);
        $this->assertResponseStatusCodeSame(201);
        $this->apiPost('/api/v1/dosare/' . $dosar['id'] . '/attach', ['declarationId' => $decl['id']], $h);
        $this->assertResponseStatusCodeSame(200);

        $req = $this->apiPost('/api/v1/spv/requests/prepare', ['type' => 'VECTOR FISCAL', 'params' => []], $h);
        $this->assertResponseStatusCodeSame(200);
        $detail = $this->apiPost('/api/v1/dosare/' . $dosar['id'] . '/attach', ['requestId' => $req['requestId']], $h);
        $this->assertSame(1, $detail['counts']['declarations']);
        $this->assertSame(1, $detail['counts']['requests']);
        $this->assertSame($dosar['id'], $detail['declarations'][0]['dosarId']);
        $this->assertNotEmpty($detail['timeline']);

        $list = $this->apiGet('/api/v1/dosare?type=rental_contract', $h);
        $this->assertContains($dosar['id'], array_column($list['data'], 'id'));
        $this->assertSame(1, $list['counts'][$dosar['id']]['declarations']);

        $actions = $this->apiGet('/api/v1/dosare/actions', $h);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertNotEmpty(array_filter($actions['todo'], fn ($i) => $i['kind'] === 'deadline' && $i['dosarId'] === $dosar['id']), 'deadline within 14 days shows up');
        $this->assertNotEmpty(array_filter($actions['inProgress'], fn ($i) => $i['kind'] === 'request'), 'pending request is in progress');

        $updated = $this->apiPatch('/api/v1/dosare/' . $dosar['id'], ['status' => 'closed', 'notes' => 'gata'], $h);
        $this->assertSame('closed', $updated['dosar']['status']);
        $this->apiDelete('/api/v1/dosare/' . $dosar['id'], $h);
        $this->assertResponseStatusCodeSame(200);
        $this->apiGet('/api/v1/declarations/' . $decl['id'], $h);
        $this->assertResponseStatusCodeSame(200, 'children survive the dosar');
    }

    public function testAnnualReturnDosarPrefillsD212FromRentalContracts(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();
        $h = ['X-Company' => $companyId];

        $this->apiPost('/api/v1/dosare', ['type' => 'rental_contract', 'subject' => ['numar' => '2', 'data' => '01.12.2023', 'adresa' => 'Apartament, Bucuresti', 'chirie' => 3000, 'moneda' => 'RON', 'deLa' => '01.12.2023']], $h);
        $this->apiPost('/api/v1/dosare', ['type' => 'rental_contract', 'subject' => ['numar' => '9', 'data' => '15.06.2025', 'adresa' => 'Garsoniera, Cluj', 'chirie' => 400, 'moneda' => 'EUR', 'deLa' => '01.07.2025']], $h);

        $annual = $this->apiPost('/api/v1/dosare/annual-return', ['an' => 2026], $h);
        $this->assertSame('annual_return', $annual['dosar']['type']);
        $this->assertSame('2026-05-25', substr($annual['dosar']['deadlineAt'], 0, 10));
        $again = $this->apiPost('/api/v1/dosare/annual-return', ['an' => 2026], $h);
        $this->assertSame($annual['dosar']['id'], $again['dosar']['id'], 'one dosar per year');

        $prefill = $this->apiGet('/api/v1/dosare/' . $annual['dosar']['id'] . '/d212-prefill', $h);
        $this->assertSame(2026, $prefill['input']['an']);
        $byContract = array_column($prefill['input']['chirii'], null, 'numarContract');
        $this->assertSame(36000, $byContract['2']['venitBrut'], '3000 × 12 months of 2025');
        $this->assertSame('01.01.2025', $byContract['2']['deLa']);
        $this->assertSame(0, $byContract['9']['venitBrut'], 'EUR rent is not converted');
        $this->assertSame('01.07.2025', $byContract['9']['deLa']);
        $this->assertNotEmpty($prefill['notes']);

        $stats = $this->apiGet('/api/v1/dosare/stats', $h);
        $this->assertGreaterThanOrEqual(2, count($stats['properties']));
        $this->assertArrayHasKey('monthlyRent', $stats);
        $this->assertSame(36000.0, (float) $stats['expectedGrossByYear'][2025]['RON'], 'expected rent 2025 from the RON contract');

        $rental = array_values(array_filter($stats['properties'], fn ($p) => $p['adresa'] === 'Apartament, Bucuresti'))[0];
        $doc = $this->apiGet('/api/v1/dosare/' . $rental['dosarId'] . '/document/conventie_incetare_inchiriere', $h);
        $this->assertSame('2', $doc['fields']['contract']['numar']);
        $this->assertSame('01.12.2023', $doc['fields']['contract']['data']);
        $this->assertContains('locatar.adresa', $doc['required']);
        $this->apiPost('/api/v1/dosare/' . $rental['dosarId'] . '/document/conventie_incetare_inchiriere', ['locatar' => ['nume' => 'IONESCU MARIA', 'adresa' => 'Bucuresti, str. Exemplu 1'], 'data_incetare' => '30.09.2026'], $h);
        $this->assertResponseStatusCodeSame(200);
        $pdf = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertStringStartsWith('JVBERi0', $pdf['pdfBase64'], 'a PDF came back');

        $decl = $this->apiPost('/api/v1/dosare/' . $annual['dosar']['id'] . '/d212', [], $h);
        $this->assertResponseStatusCodeSame(201);
        $this->assertSame('d212', $decl['type']);
        $this->assertSame(2026, $decl['year']);
        $this->assertSame($annual['dosar']['id'], $decl['dosarId']);
        $this->assertGreaterThanOrEqual(2, count($decl['data']['input']['chirii']));
        foreach ($this->apiGet('/api/v1/dosare', $h)['data'] as $d) {
            $this->apiDelete('/api/v1/dosare/' . $d['id'], $h);
        }
    }

    public function testRecipisaFromTheInboxLandsInTheDeclarationsDosar(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();
        $h = ['X-Company' => $companyId];

        $dosar = $this->apiPost('/api/v1/dosare', ['type' => 'generic', 'title' => 'Test'], $h)['dosar'];
        $decl = $this->apiPost('/api/v1/declarations', ['type' => 'd300', 'year' => 2026, 'month' => 6], $h);
        $this->apiPost('/api/v1/dosare/' . $dosar['id'] . '/attach', ['declarationId' => $decl['id']], $h);

        $em = static::getContainer()->get('doctrine')->getManager();
        $entity = $em->getRepository(\App\Entity\TaxDeclaration::class)->find($decl['id']);
        $index = (string) random_int(1216000000, 1216999999);
        $entity->setAnafUploadId($index);
        $em->flush();
        $cif = (string) $entity->getCompany()->getCif();

        $this->apiPost('/api/v1/spv/sync-agent-result', ['statusCode' => 200, 'body' => json_encode(['mesaje' => [
            ['id' => (string) random_int(900000000, 999999999), 'detalii' => sprintf('recipisa pentru CIF %s, tip D300, numar_inregistrare INTERNT-%s-2026/04-09-2026, perioada raportare 6.2026', $cif, $index), 'cif' => $cif, 'data_creare' => '05092026011028', 'tip' => 'RECIPISA', 'id_solicitare' => ''],
        ]])], $h);
        $this->assertResponseStatusCodeSame(200);

        $detail = $this->apiGet('/api/v1/dosare/' . $dosar['id'], $h);
        $this->assertSame(1, $detail['counts']['documents'], 'the recipisa was linked through the upload index');
        $this->assertSame('RECIPISA', $detail['documents'][0]['messageType']);
        $this->apiDelete('/api/v1/dosare/' . $dosar['id'], $h);
    }

    public function testFilesC168AndDocumentsFromTheDosar(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();
        $h = ['X-Company' => $companyId];
        $adresa = ['tara' => 'RO', 'judet' => '40', 'localitate' => '6', 'localitateNume' => '6 Sector - Mun. Bucuresti', 'strada' => '412', 'stradaNume' => 'Bld. Iuliu Maniu', 'numar' => '7', 'detalii' => 'bl. 1, ap. 16', 'codPostal' => '061072'];
        $dosar = $this->apiPost('/api/v1/dosare', ['type' => 'rental_contract', 'subject' => ['numar' => '12', 'data' => '01.03.2026', 'adresa' => 'Bld. Iuliu Maniu 7, ap. 16', 'chirias' => 'IONESCU MARIA', 'chiriasCif' => '2850505400014', 'chirie' => 2500, 'moneda' => 'RON', 'deLa' => '01.03.2026', 'panaLa' => '28.02.2027', 'adresaCod' => $adresa, 'chiriasAdresaCod' => $adresa + ['numar' => '9'], 'locatorAdresaCod' => $adresa]], $h)['dosar'];

        // prefill: the C168 input from the dosar, with Storno's rule issues
        $pre = $this->apiGet('/api/v1/dosare/' . $dosar['id'] . '/c168-prefill?actiune=inregistrare', $h);
        $this->assertSame('12', $pre['input']['contracte'][0]['numar']);
        $this->assertSame('412', $pre['input']['contracte'][0]['bun']['adresa']['strada']);
        $this->assertSame('inregistrare', $pre['actiune']);

        // no attachment → refused
        $this->apiPost('/api/v1/dosare/' . $dosar['id'] . '/c168', ['actiune' => 'inregistrare', 'input' => $pre['input']], $h);
        $this->assertResponseStatusCodeSame(422);

        // upload the contract scan into the dosar
        $tmp = tempnam(sys_get_temp_dir(), 'c168') . '.pdf';
        file_put_contents($tmp, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF");
        $this->client->request('POST', '/api/v1/dosare/' . $dosar['id'] . '/files', ['kind' => 'contract'], ['file' => new UploadedFile($tmp, 'contract.pdf', 'application/pdf', null, true)], ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->token, 'HTTP_X_COMPANY' => $companyId]);
        $this->assertResponseStatusCodeSame(201);
        $detail = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertCount(1, $detail['files']);
        $fileId = $detail['files'][0]['id'];
        $this->assertSame('contract', $detail['files'][0]['kind']);

        $this->client->request('GET', '/api/v1/dosare/' . $dosar['id'] . '/files/' . $fileId . '/download', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->token, 'HTTP_X_COMPANY' => $companyId]);
        $this->assertResponseStatusCodeSame(200);
        $this->assertStringStartsWith('%PDF', (string) $this->client->getResponse()->getContent());

        // C168 declaration created in the dosar with the file as attachment
        $created = $this->apiPost('/api/v1/dosare/' . $dosar['id'] . '/c168', ['actiune' => 'inregistrare', 'input' => $pre['input'], 'fileIds' => [$fileId]], $h);
        $this->assertResponseStatusCodeSame(201, json_encode($created));
        $this->assertSame('c168', $created['declaration']['type']);
        $this->assertSame($dosar['id'], $created['declaration']['dosarId']);
        $this->assertStringContainsString('<c168 xmlns="mfp:anaf:dgti:c168:declaratie:v3"', $created['xml']);
        $this->assertCount(1, $created['declaration']['data']['attachments']);

        // termination: prefill carries the termination block and the addendum / notice documents prefill too
        $this->apiPatch('/api/v1/dosare/' . $dosar['id'], ['subject' => ['dataIncetare' => '30.09.2026']], $h);
        $inc = $this->apiGet('/api/v1/dosare/' . $dosar['id'] . '/c168-prefill?actiune=incetare', $h);
        $this->assertSame('30.09.2026', $inc['input']['contracte'][0]['incetare']['deLa']);
        $act = $this->apiGet('/api/v1/dosare/' . $dosar['id'] . '/document/act_aditional_inchiriere', $h);
        $this->assertSame('12', $act['fields']['contract']['numar']);
        $this->apiPost('/api/v1/dosare/' . $dosar['id'] . '/document/notificare_incetare_inchiriere', ['data_incetare' => '30.09.2026', 'preaviz_zile' => 30], $h);
        $this->assertResponseStatusCodeSame(200);
        $this->apiPost('/api/v1/dosare/' . $dosar['id'] . '/document/act_aditional_inchiriere', ['locatar' => ['adresa' => 'Bucuresti'], 'act' => ['numar' => '1', 'data' => '01.09.2026'], 'prelungire' => ['data_sfarsit' => '28.02.2028']], $h);
        $this->assertResponseStatusCodeSame(200);

        // CSV export of the portfolio
        $this->client->request('GET', '/api/v1/dosare/stats?format=csv', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->token, 'HTTP_X_COMPANY' => $companyId]);
        $this->assertResponseStatusCodeSame(200);
        $this->assertStringContainsString('text/csv', (string) $this->client->getResponse()->headers->get('Content-Type'));
        $this->assertStringContainsString('Iuliu Maniu', (string) $this->client->getResponse()->getContent());

        $this->apiDelete('/api/v1/dosare/' . $dosar['id'] . '/files/' . $fileId, $h);
        $this->assertResponseStatusCodeSame(200);
        $this->apiDelete('/api/v1/dosare/' . $dosar['id'], $h);
        @unlink($tmp);
    }
}
