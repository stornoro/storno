<?php

namespace App\Tests\Api;

class AnafTokenTest extends ApiTestCase
{
    public function testListTokensEmpty(): void
    {
        $this->login();
        $data = $this->apiGet('/api/v1/anaf/tokens');
        $this->assertResponseIsSuccessful();
        $this->assertArrayHasKey('data', $data);
        $this->assertIsArray($data['data']);
    }

    public function testCreateToken(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $data = $this->apiPost('/api/v1/anaf/tokens', [
            'token' => str_repeat('a', 50),
            'refreshToken' => str_repeat('b', 50),
            'expiresInDays' => 90,
            'label' => 'Test token',
            'companyId' => $companyId,
        ]);

        $this->assertResponseStatusCodeSame(201);
        $this->assertSame('ok', $data['status']);
        $this->assertArrayHasKey('data', $data);
        $this->assertSame('Test token', $data['data']['label']);
        $this->assertFalse($data['data']['isExpired']);
    }

    public function testCreateTokenMinLength(): void
    {
        $this->login();
        $this->apiPost('/api/v1/anaf/tokens', [
            'token' => 'short',
        ]);
        $this->assertResponseStatusCodeSame(400);
    }

    public function testListTokensAfterCreate(): void
    {
        $this->login();

        // Create a token first
        $this->apiPost('/api/v1/anaf/tokens', [
            'token' => str_repeat('c', 50),
            'label' => 'List test token',
        ]);
        $this->assertResponseStatusCodeSame(201);

        $data = $this->apiGet('/api/v1/anaf/tokens');
        $this->assertResponseIsSuccessful();
        $this->assertNotEmpty($data['data']);

        $found = false;
        foreach ($data['data'] as $token) {
            $this->assertArrayHasKey('id', $token);
            $this->assertArrayHasKey('label', $token);
            $this->assertArrayHasKey('expiresAt', $token);
            $this->assertArrayHasKey('isExpired', $token);
            $this->assertArrayHasKey('validatedCifs', $token);
            if ($token['label'] === 'List test token') {
                $found = true;
            }
        }
        $this->assertTrue($found, 'Created token should appear in list');
    }

    public function testDeleteToken(): void
    {
        $this->login();

        // Create a token
        $createData = $this->apiPost('/api/v1/anaf/tokens', [
            'token' => str_repeat('d', 50),
            'label' => 'Token to delete',
        ]);
        $this->assertResponseStatusCodeSame(201);
        $tokenId = $createData['data']['id'];

        // Delete it
        $this->apiDelete('/api/v1/anaf/tokens/' . $tokenId);
        $this->assertResponseIsSuccessful();

        // Verify it's gone
        $data = $this->apiGet('/api/v1/anaf/tokens');
        foreach ($data['data'] as $token) {
            $this->assertNotSame($tokenId, $token['id'], 'Deleted token should not appear in list');
        }
    }

    public function testDeleteTokenNotFound(): void
    {
        $this->login();
        $this->apiDelete('/api/v1/anaf/tokens/01961234-5678-7abc-def0-123456789abc');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testStatus(): void
    {
        $this->login();
        $data = $this->apiGet('/api/v1/anaf/status');
        $this->assertResponseIsSuccessful();
        $this->assertArrayHasKey('connected', $data);
        $this->assertArrayHasKey('hasToken', $data);
        $this->assertArrayHasKey('tokenCount', $data);
    }

    public function testStatusWithToken(): void
    {
        $this->login();

        // Create a token
        $this->apiPost('/api/v1/anaf/tokens', [
            'token' => str_repeat('e', 50),
        ]);
        $this->assertResponseStatusCodeSame(201);

        $data = $this->apiGet('/api/v1/anaf/status');
        $this->assertResponseIsSuccessful();
        $this->assertTrue($data['hasToken']);
        $this->assertGreaterThanOrEqual(1, $data['tokenCount']);
    }

    public function testCreateTokenLink(): void
    {
        $this->login();
        $data = $this->apiPost('/api/v1/anaf/token-links');
        $this->assertResponseStatusCodeSame(201);
        $this->assertArrayHasKey('linkToken', $data);
        $this->assertArrayHasKey('expiresAt', $data);
        $this->assertSame(64, strlen($data['linkToken']));
    }

    public function testCheckTokenLink(): void
    {
        $this->login();

        // Create a link
        $linkData = $this->apiPost('/api/v1/anaf/token-links');
        $this->assertResponseStatusCodeSame(201);
        $linkToken = $linkData['linkToken'];

        // Check it
        $data = $this->apiGet('/api/v1/anaf/token-links/' . $linkToken);
        $this->assertResponseIsSuccessful();
        $this->assertFalse($data['expired']);
        $this->assertFalse($data['used']);
        $this->assertFalse($data['completed']);
    }

    public function testCheckTokenLinkNotFound(): void
    {
        $this->login();
        $this->apiGet('/api/v1/anaf/token-links/nonexistent');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testTokensUnauthenticated(): void
    {
        $this->client->request('GET', '/api/v1/anaf/tokens', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ]);
        $this->assertResponseStatusCodeSame(401);
    }

    public function testMultipleTokens(): void
    {
        $this->login();

        // Create two tokens
        $this->apiPost('/api/v1/anaf/tokens', [
            'token' => str_repeat('f', 50),
            'label' => 'Multi test 1',
        ]);
        $this->assertResponseStatusCodeSame(201);

        $this->apiPost('/api/v1/anaf/tokens', [
            'token' => str_repeat('g', 50),
            'label' => 'Multi test 2',
        ]);
        $this->assertResponseStatusCodeSame(201);

        $data = $this->apiGet('/api/v1/anaf/tokens');
        $this->assertResponseIsSuccessful();

        $labels = array_column($data['data'], 'label');
        $this->assertContains('Multi test 1', $labels);
        $this->assertContains('Multi test 2', $labels);
    }

    public function testCompanyCreateReturnsHasValidToken(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();
        $data = $this->apiGet('/api/v1/companies/' . $companyId);
        $this->assertResponseIsSuccessful();
        $this->assertArrayHasKey('hasValidToken', $data);
    }

    public function testLinkPageValid(): void
    {
        $this->login();

        // Create a link
        $linkData = $this->apiPost('/api/v1/anaf/token-links');
        $this->assertResponseStatusCodeSame(201);
        $linkToken = $linkData['linkToken'];

        // Access the link page (no auth needed)
        $this->client->request('GET', '/anaf/link/' . $linkToken);
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Conecteaza la ANAF', $this->client->getResponse()->getContent());
    }

    public function testLinkPageInvalid(): void
    {
        $this->client->request('GET', '/anaf/link/nonexistent-token');
        $this->assertResponseStatusCodeSame(404);
        $this->assertStringContainsString('expirat', $this->client->getResponse()->getContent());
    }

    public function testCreateTokenWithJwtExpiry(): void
    {
        $this->login();

        // Build a fake JWT with exp = 2025-12-31T00:00:00Z (1767225600)
        $header = base64_encode(json_encode(['alg' => 'RS256']));
        $payload = base64_encode(json_encode([
            'exp' => 1767225600,
            'iat' => time(),
            'sub' => 'test',
        ]));
        $signature = base64_encode('fake-signature-padding-data');
        $jwt = "$header.$payload.$signature";

        $data = $this->apiPost('/api/v1/anaf/tokens', [
            'token' => $jwt,
            'label' => 'JWT test token',
        ]);

        $this->assertResponseStatusCodeSame(201);
        $this->assertSame('ok', $data['status']);

        // Verify the expiry was extracted from the JWT
        $expiresAt = new \DateTimeImmutable($data['data']['expiresAt']);
        $expected = \DateTimeImmutable::createFromFormat('U', '1767225600');
        $this->assertSame($expected->format('Y-m-d'), $expiresAt->format('Y-m-d'));
    }
}
