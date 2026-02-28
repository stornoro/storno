<?php

namespace App\Tests\Api;

class AuthTest extends ApiTestCase
{
    private function jsonHeaders(): array
    {
        return [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ];
    }

    public function testLoginSuccess(): void
    {
        $this->client->request('POST', '/api/auth', [], [], $this->jsonHeaders(), json_encode([
            'email' => 'admin@localhost.com',
            'password' => 'password',
        ]));

        $this->assertResponseStatusCodeSame(200);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('token', $data);
        $this->assertArrayHasKey('refresh_token', $data);
        $this->assertNotEmpty($data['token']);
        $this->assertNotEmpty($data['refresh_token']);
    }

    public function testLoginWrongPassword(): void
    {
        $this->client->request('POST', '/api/auth', [], [], $this->jsonHeaders(), json_encode([
            'email' => 'admin@localhost.com',
            'password' => 'wrong-password',
        ]));

        $this->assertResponseStatusCodeSame(401);
    }

    public function testLoginMissingEmail(): void
    {
        $this->client->request('POST', '/api/auth', [], [], $this->jsonHeaders(), json_encode([
            'password' => 'password',
        ]));

        $this->assertResponseStatusCodeSame(400);
    }

    public function testRegister(): void
    {
        $uniqueEmail = 'test-' . uniqid() . '@example.com';

        $this->client->request('POST', '/api/auth/register', [], [], $this->jsonHeaders(), json_encode([
            'firstName' => 'Test',
            'lastName' => 'User',
            'email' => $uniqueEmail,
            'password' => 'securePassword123!',
        ]));

        $this->assertResponseStatusCodeSame(201);
    }

    public function testRegisterDuplicateEmail(): void
    {
        $duplicateEmail = 'duplicate-' . uniqid() . '@example.com';
        $payload = json_encode([
            'firstName' => 'Test',
            'lastName' => 'User',
            'email' => $duplicateEmail,
            'password' => 'securePassword123!',
        ]);

        // First registration should succeed
        $this->client->request('POST', '/api/auth/register', [], [], $this->jsonHeaders(), $payload);
        $this->assertResponseStatusCodeSame(201);

        // Second registration with the same email should fail
        $this->client->request('POST', '/api/auth/register', [], [], $this->jsonHeaders(), $payload);
        $this->assertResponseStatusCodeSame(409);
    }

    public function testForgotPassword(): void
    {
        $this->client->request('POST', '/api/auth/forgot-password', [], [], $this->jsonHeaders(), json_encode([
            'email' => 'nonexistent-' . uniqid() . '@example.com',
        ]));

        // Should return 200 even if the email doesn't exist, for security reasons
        $this->assertResponseStatusCodeSame(200);
    }

    public function testAccessProtectedRouteWithoutToken(): void
    {
        $this->client->request('GET', '/api/v1/me', [], [], $this->jsonHeaders());

        $this->assertResponseStatusCodeSame(401);
    }

    public function testTokenRefresh(): void
    {
        // First, log in to obtain a valid refresh token
        $this->client->request('POST', '/api/auth', [], [], $this->jsonHeaders(), json_encode([
            'email' => 'admin@localhost.com',
            'password' => 'password',
        ]));

        $this->assertResponseStatusCodeSame(200);

        $loginData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('refresh_token', $loginData);

        // Now use the refresh token to get a new access token
        $this->client->request('POST', '/api/auth/refresh', [], [], $this->jsonHeaders(), json_encode([
            'refresh_token' => $loginData['refresh_token'],
        ]));

        $this->assertResponseStatusCodeSame(200);

        $refreshData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('token', $refreshData);
        $this->assertNotEmpty($refreshData['token']);
    }
}
