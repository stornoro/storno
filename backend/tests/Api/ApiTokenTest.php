<?php

namespace App\Tests\Api;

use App\DataFixtures\ApiTokenFixtures;

class ApiTokenTest extends ApiTestCase
{
    public function testListTokens(): void
    {
        $this->login();

        $data = $this->apiGet('/api/v1/api-tokens');

        $this->assertResponseStatusCodeSame(200);
        $this->assertArrayHasKey('data', $data);
        $this->assertIsArray($data['data']);
        $this->assertNotEmpty($data['data']);

        // Verify token hash is never exposed
        foreach ($data['data'] as $token) {
            $this->assertArrayHasKey('tokenPrefix', $token);
            $this->assertArrayNotHasKey('tokenHash', $token);
        }
    }

    public function testCreateToken(): void
    {
        $this->login();

        $data = $this->apiPost('/api/v1/api-tokens', [
            'name' => 'Test API Key',
            'scopes' => ['invoice.view', 'client.view'],
        ]);

        $this->assertResponseStatusCodeSame(201);
        $this->assertArrayHasKey('token', $data);
        $this->assertStringStartsWith('af_', $data['token']);
        $this->assertEquals('Test API Key', $data['name']);
        $this->assertArrayHasKey('tokenPrefix', $data);
        $this->assertEquals(['invoice.view', 'client.view'], $data['scopes']);

        // Subsequent list should show prefix only, not raw token
        $listData = $this->apiGet('/api/v1/api-tokens');
        $found = false;
        foreach ($listData['data'] as $t) {
            if ($t['name'] === 'Test API Key') {
                $found = true;
                $this->assertArrayNotHasKey('token', $t);
                $this->assertArrayHasKey('tokenPrefix', $t);
            }
        }
        $this->assertTrue($found, 'Created token should appear in list');
    }

    public function testCreateTokenValidatesScopes(): void
    {
        $this->login();

        $this->apiPost('/api/v1/api-tokens', [
            'name' => 'Bad Scopes Key',
            'scopes' => ['nonexistent.scope', 'invalid.permission'],
        ]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testCreateTokenRequiresName(): void
    {
        $this->login();

        $this->apiPost('/api/v1/api-tokens', [
            'scopes' => ['invoice.view'],
        ]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testCreateTokenRequiresScopes(): void
    {
        $this->login();

        $this->apiPost('/api/v1/api-tokens', [
            'name' => 'No Scopes Key',
            'scopes' => [],
        ]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testUpdateTokenName(): void
    {
        $this->login();

        // Create a token first
        $created = $this->apiPost('/api/v1/api-tokens', [
            'name' => 'Original Name',
            'scopes' => ['invoice.view'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        // Update the name
        $updated = $this->apiPatch('/api/v1/api-tokens/' . $created['id'], [
            'name' => 'Updated Name',
        ]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertEquals('Updated Name', $updated['name']);
    }

    public function testRevokeToken(): void
    {
        $this->login();

        // Create a token
        $created = $this->apiPost('/api/v1/api-tokens', [
            'name' => 'To Be Revoked',
            'scopes' => ['invoice.view'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        // Revoke it
        $this->apiDelete('/api/v1/api-tokens/' . $created['id']);
        $this->assertResponseStatusCodeSame(204);

        // Check it shows as revoked in the list
        $listData = $this->apiGet('/api/v1/api-tokens');
        foreach ($listData['data'] as $t) {
            if ($t['id'] === $created['id']) {
                $this->assertNotNull($t['revokedAt']);
            }
        }
    }

    public function testRevokedTokenCannotAuth(): void
    {
        $this->login();

        // Create and revoke a token
        $created = $this->apiPost('/api/v1/api-tokens', [
            'name' => 'Revoke Auth Test',
            'scopes' => ['invoice.view'],
        ]);
        $this->assertResponseStatusCodeSame(201);
        $rawToken = $created['token'];

        $this->apiDelete('/api/v1/api-tokens/' . $created['id']);
        $this->assertResponseStatusCodeSame(204);

        // Try to authenticate with the revoked token
        $this->token = null;
        $this->client->request('GET', '/api/v1/api-tokens', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => $rawToken,
        ]);

        $this->assertResponseStatusCodeSame(403);
    }

    public function testExpiredTokenCannotAuth(): void
    {
        // Use the known expired fixture token
        $this->client->request('GET', '/api/v1/api-tokens', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => ApiTokenFixtures::TEST_TOKEN_EXPIRED,
        ]);

        $this->assertResponseStatusCodeSame(403);
    }

    public function testApiKeyAuthWithScopes(): void
    {
        // Use fixture token 1 which has settings.view scope — access api-tokens list
        $this->client->request('GET', '/api/v1/api-tokens', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => ApiTokenFixtures::TEST_TOKEN_1,
        ]);

        // Should succeed — token has settings.view
        $this->assertResponseStatusCodeSame(200);
    }

    public function testApiKeyAuthSetsLastUsedAt(): void
    {
        // Authenticate with fixture token to trigger lastUsedAt update
        $this->client->request('GET', '/api/v1/api-tokens', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => ApiTokenFixtures::TEST_TOKEN_1,
        ]);
        $this->assertResponseStatusCodeSame(200);

        // Now log in as the same user via JWT and check the token's lastUsedAt
        $this->login();
        $data = $this->apiGet('/api/v1/api-tokens');
        $found = false;
        foreach ($data['data'] as $t) {
            if ($t['name'] === 'Integrare ERP') {
                $found = true;
                $this->assertNotNull($t['lastUsedAt']);
            }
        }
        $this->assertTrue($found, 'Fixture token should exist');
    }

    public function testGetAvailableScopes(): void
    {
        $this->login();

        $data = $this->apiGet('/api/v1/api-tokens/scopes');

        $this->assertResponseStatusCodeSame(200);
        $this->assertArrayHasKey('scopes', $data);
        $this->assertNotEmpty($data['scopes']);

        // Each scope should have value, label, and category
        foreach ($data['scopes'] as $scope) {
            $this->assertArrayHasKey('value', $scope);
            $this->assertArrayHasKey('label', $scope);
            $this->assertArrayHasKey('category', $scope);
        }
    }

    public function testListTokensUnauthenticated(): void
    {
        $this->apiGet('/api/v1/api-tokens');

        $this->assertResponseStatusCodeSame(401);
    }
}
