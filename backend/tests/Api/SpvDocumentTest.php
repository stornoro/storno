<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Service\Spv\SpvDocumentClassifier;

class SpvDocumentTest extends ApiTestCase
{
    public function testClassifierBucketsKnownAnafTypes(): void
    {
        $c = new SpvDocumentClassifier();

        $expect = [
            'SOMATII' => ['somatie', 'critical'],
            'RAPOARTE ANALIZA DE RISC' => ['analiza_risc', 'critical'],
            'Decizie inactivare' => ['decizie', 'critical'],
            'Decizie privind anularea din oficiu a inregistrarii in scopuri de TVA' => ['decizie', 'critical'],
            'DECIZIE' => ['decizie', 'high'],
            'Decizie inregistrare TVA' => ['decizie', 'high'],
            'NOTIFICARE' => ['notificare', 'high'],
            'Notificare hotărâre judecătorească' => ['notificare', 'high'],
            'Inștiințare pentru depunere documente suplimentare' => ['notificare', 'high'],
            'Invitatie privind inregistrarea, din oficiu, in scopuri de TVA' => ['notificare', 'high'],
            'ADRESE' => ['adresa', 'high'],
            'RECIPISA' => ['recipisa', 'low'],
            'RECIPISA TREZORERIE' => ['recipisa', 'low'],
            'Recipisa Program Tezaur' => ['tezaur', 'low'],
            'CERTIFICAT FISCAL' => ['certificat', 'normal'],
            'CAZIER FISCAL' => ['certificat', 'normal'],
            'ADEVERINTA VENIT' => ['certificat', 'normal'],
            'RASPUNS SESIZARE FORMULAR UNIC DE CONTACT' => ['raspuns', 'normal'],
            'EXTRAS DE CONT' => ['extras_cont', 'normal'],
            'PLATA' => ['plata', 'normal'],
            'DECLARATIE ' => ['declaratie', 'low'],
            'Decont pe taxa de valoare adaugata (D300) proiect pilot SAFT' => ['declaratie', 'low'],
            'FACTURI ARHIVA' => ['facturi_arhiva', 'low'],
            'Certificat fiducie' => ['certificat', 'normal'],
            'SME_Decizie' => ['decizie', 'high'],
            'ceva complet nou' => ['altele', 'normal'],
        ];

        foreach ($expect as $tip => [$category, $severity]) {
            $r = $c->classify($tip);
            $this->assertSame($category, $r['category']->value, "category for '$tip'");
            $this->assertSame($severity, $r['severity']->value, "severity for '$tip'");
        }
    }

    public function testSyncAgentResultArchivesEveryMessageAndReturnsPendingDownloads(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();
        $suffix = substr(md5(uniqid('', true)), 0, 6);

        $prepare = $this->apiPost('/api/v1/spv/sync-prepare', ['days' => 30], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);
        $this->assertStringContainsString('/SPVWS2/rest/listaMesaje?zile=30&cif=', $prepare['anafUrl']);
        $cif = $prepare['cif'];

        $body = json_encode([
            'titlu' => 'Lista Mesaje disponibile din ultimele 30 zile',
            'mesaje' => [
                ['id' => "9001$suffix", 'tip' => 'SOMATII', 'cif' => $cif, 'detalii' => 'Somatie nr. 123', 'data_creare' => '03092026141500'],
                ['id' => "9002$suffix", 'tip' => 'RECIPISA', 'cif' => $cif, 'detalii' => 'Recipisa D300', 'data_creare' => '02092026090000', 'id_solicitare' => '55'],
                ['id' => "9003$suffix", 'tip' => 'CERTIFICAT FISCAL', 'cif' => $cif, 'detalii' => 'Certificat', 'data_creare' => '20260901'],
                ['id' => "9004$suffix", 'tip' => 'SOMATII', 'cif' => '99999999', 'detalii' => 'alt CUI', 'data_creare' => '202609031415'],
            ],
        ]);

        $result = $this->apiPost('/api/v1/spv/sync-agent-result', ['statusCode' => 200, 'body' => $body], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);
        $this->assertSame(3, $result['stats']['created']);
        $this->assertSame(1, $result['stats']['skipped']);
        $ids = array_column($result['documents'], 'anafUrl');
        $this->assertNotEmpty(array_filter($ids, fn ($u) => str_contains($u, "descarcare?id=9001$suffix")));

        // idempotent: same payload again creates nothing
        $again = $this->apiPost('/api/v1/spv/sync-agent-result', ['statusCode' => 200, 'body' => $body], ['X-Company' => $companyId]);
        $this->assertSame(0, $again['stats']['created']);

        // listing + filters
        $list = $this->apiGet('/api/v1/spv/documents?category=somatie&unread=1', ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);
        $somatii = array_filter($list['data'], fn ($d) => $d['anafMessageId'] === "9001$suffix");
        $this->assertCount(1, $somatii);
        $somatie = array_values($somatii)[0];
        $this->assertSame('critical', $somatie['severity']);
        $this->assertFalse($somatie['hasPdf']);
        $this->assertFalse($somatie['read']);

        // upload the PDF the agent fetched, then download it back
        $pdf = "%PDF-1.4\n1 0 obj << >> endobj\n%%EOF";
        $stored = $this->apiPost("/api/v1/spv/documents/{$somatie['id']}/agent-document", [
            'statusCode' => 200,
            'body' => base64_encode($pdf),
            'bodyEncoding' => 'base64',
        ], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);
        $this->assertTrue($stored['hasPdf']);
        $this->assertStringEndsWith('.pdf', $stored['fileName']);

        $this->client->request('GET', "/api/v1/spv/documents/{$somatie['id']}/download", [], [], $this->buildHeaders(['X-Company' => $companyId]));
        $this->assertResponseStatusCodeSame(200);
        $this->assertSame('application/pdf', $this->client->getResponse()->headers->get('Content-Type'));
        $this->assertSame($pdf, $this->client->getResponse()->getContent());

        // an HTML "Pagina logout" body must never be archived as a document
        $html = $this->apiPost("/api/v1/spv/documents/{$somatie['id']}/agent-document", [
            'statusCode' => 200,
            'body' => '<html><head><title>Pagina logout</title></head></html>',
        ], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(502);
        $this->assertSame('SPV_NOT_A_DOCUMENT', $html['code']);

        $stats = $this->apiGet('/api/v1/spv/documents/stats', ['X-Company' => $companyId]);
        $this->assertGreaterThanOrEqual(3, $stats['total']);
        $this->assertArrayHasKey('somatie', $stats['byCategory']);

        // download marked it read; the others are still unread
        $read = $this->apiPost('/api/v1/spv/documents/read-all', [], ['X-Company' => $companyId]);
        $this->assertGreaterThanOrEqual(2, $read['updated']);
    }

    public function testAgentResultWithLogoutPageIsRejected(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $result = $this->apiPost('/api/v1/spv/sync-agent-result', [
            'statusCode' => 200,
            'body' => '<html><head><title>Pagina logout</title></head><body></body></html>',
        ], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(502);
        $this->assertSame('SPV_UNPARSEABLE', $result['code']);
    }
}
