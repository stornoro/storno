<?php

declare(strict_types=1);

namespace App\Tests\Api;

/**
 * A draft declaration must never become "validated" on a syntax check alone:
 * with the Java service running, ANAF's validator is consulted and an empty
 * D300 is rejected with ANAF's own messages; without it, validation fails loudly.
 */
class DeclarationValidateWithDukTest extends ApiTestCase
{
    public function testEmptyDraftIsRejectedByAnafValidator(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $created = $this->apiPost('/api/v1/declarations', ['type' => 'd300', 'year' => 2026, 'month' => 7], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(201);
        $id = $created['id'];

        $this->client->request('GET', '/api/v1/declarations/' . $id . '/xml', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token,
            'HTTP_X_COMPANY' => $companyId,
        ]);
        $xml = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('<declaratie300 xmlns="mfp:anaf:dgti:d300:declaratie:v', $xml, 'generated XML must carry the ANAF namespace');

        $this->apiPost('/api/v1/declarations/' . $id . '/validate', [], ['X-Company' => $companyId]);
        $status = $this->client->getResponse()->getStatusCode();
        $body = (string) $this->client->getResponse()->getContent();

        $this->assertNotSame(200, $status, 'an empty declaration must not validate: ' . $body);
        $this->assertTrue(
            str_contains($body, 'DUK validation failed') || str_contains($body, 'DUKIntegrator'),
            'expected ANAF validator errors or an explicit validator-unavailable error, got: ' . $body
        );

        $detail = $this->apiGet('/api/v1/declarations/' . $id, ['X-Company' => $companyId]);
        $this->assertSame('draft', $detail['status']);
    }
}
