<?php

declare(strict_types=1);

namespace App\Tests\Api;

/**
 * SPV requests (solicitari via SPVWS2 cerere): catalog, prepare with parameter
 * validation, the agent relaying ANAF's answer, and the inbox sync linking the
 * answer document to the request.
 */
class SpvRequestTest extends ApiTestCase
{
    public function testCatalogAndParameterValidation(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $types = $this->apiGet('/api/v1/spv/requests/types', ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);
        $byType = array_column($types['types'], null, 'type');
        $this->assertSame(['an', 'luna'], $byType['D300']['params']);
        $this->assertSame(['numar_inregistrare'], $byType['Duplicat Recipisa']['params']);
        $this->assertSame(['an', 'motiv'], $byType['Adeverinte Venit']['params']);
        $this->assertContains('Pensie', $types['incomeCertificateReasons']);
        $this->assertSame(2011, $byType['D300']['since']);

        // missing month
        $this->apiPost('/api/v1/spv/requests/prepare', ['type' => 'D300', 'params' => ['an' => '2026']], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(422);

        // before data exists at ANAF
        $this->apiPost('/api/v1/spv/requests/prepare', ['type' => 'D394', 'params' => ['an' => '2010', 'luna' => '3']], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(422);

        // unknown reason for the income certificate
        $this->apiPost('/api/v1/spv/requests/prepare', ['type' => 'Adeverinte Venit', 'params' => ['an' => '2025', 'motiv' => 'Orice']], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(422);

        // unknown type
        $this->apiPost('/api/v1/spv/requests/prepare', ['type' => 'CAF', 'params' => []], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(422);

        // exists in the SPV website form but the web service rejects it (verified live: "tip raport= C168 necunoscut"):
        // the agent goes through the website form instead
        $this->assertFalse($byType['C168']['wsSupported']);
        $this->assertTrue($byType['Fisa Rol Completa']['wsSupported']);
        $c168 = $this->apiPost('/api/v1/spv/requests/prepare', ['type' => 'C168', 'params' => []], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);
        $this->assertSame('web', $c168['channel']);
        $this->assertNull($c168['anafUrl']);
        $this->assertSame('C168', $c168['form']['tipDocument']);
        $this->apiPost('/api/v1/spv/requests/' . $c168['requestId'] . '/agent-result', ['statusCode' => 200, 'body' => json_encode(['titlu' => 'Transmitere cerere tip C168', 'id_solicitare' => '192109803', 'canal' => 'spv-web'])], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);
    }

    public function testPrepareAgentResultAndLinkToInboxDocument(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $prepared = $this->apiPost('/api/v1/spv/requests/prepare', ['type' => 'Fisa Rol', 'params' => []], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200, json_encode($prepared));
        $this->assertSame('ws', $prepared['channel']);
        $this->assertStringStartsWith('https://webserviced.anaf.ro/SPVWS2/rest/cerere?tip=Fisa%20Rol&cui=', $prepared['anafUrl']);
        $requestId = $prepared['requestId'];

        // ANAF accepted the request
        $anafRequestId = (string) random_int(200000000, 299999999);
        $anafMessageId = (string) random_int(100000000, 199999999);
        $accepted = $this->apiPost('/api/v1/spv/requests/' . $requestId . '/agent-result', [
            'statusCode' => 200,
            'body' => json_encode(['id_solicitare' => (int) $anafRequestId, 'titlu' => 'Transmitere cerere tip Fisa Rol', 'parametri' => 'cui=12345678']),
        ], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);
        $this->assertSame('requested', $accepted['status']);
        $this->assertSame($anafRequestId, $accepted['anafRequestId']);

        // the answer shows up in listaMesaje with our id_solicitare
        $this->apiPost('/api/v1/spv/sync-agent-result', [
            'statusCode' => 200,
            'body' => json_encode(['mesaje' => [
                ['id' => $anafMessageId, 'tip' => 'Fisa Rol', 'cif' => $prepared['cif'], 'detalii' => 'Fisa rol pentru CIF ' . $prepared['cif'], 'data_creare' => '04092026101500', 'id_solicitare' => $anafRequestId],
            ]]),
        ], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);

        $list = $this->apiGet('/api/v1/spv/requests', ['X-Company' => $companyId]);
        $row = null;
        foreach ($list['data'] as $item) {
            if ($item['id'] === $requestId) {
                $row = $item;
            }
        }
        $this->assertNotNull($row);
        $this->assertSame('answered', $row['status']);
        $this->assertNotNull($row['answerDocumentId']);
        $this->assertNotNull($row['answeredAt']);

        // ANAF refused a second request
        $prepared2 = $this->apiPost('/api/v1/spv/requests/prepare', ['type' => 'VECTOR FISCAL', 'params' => []], ['X-Company' => $companyId]);
        $refused = $this->apiPost('/api/v1/spv/requests/' . $prepared2['requestId'] . '/agent-result', [
            'statusCode' => 200,
            'body' => json_encode(['eroare' => 'Nu aveti drept sa solicitati informatii despre CIF=12345678', 'titlu' => 'Cerere']),
        ], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(422);
        $this->assertSame('error', $refused['status']);
        $this->assertStringContainsString('Nu aveti drept', $refused['errorMessage']);
    }
}
