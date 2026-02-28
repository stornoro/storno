<?php

namespace App\Tests\Api;

class UserTest extends ApiTestCase
{
    public function testGetMe(): void
    {
        $this->login('admin@localhost.com');

        $data = $this->apiGet('/api/v1/me');

        $this->assertResponseStatusCodeSame(200);
        $this->assertArrayHasKey('email', $data);
        $this->assertArrayHasKey('firstName', $data);
        $this->assertArrayHasKey('lastName', $data);
        $this->assertArrayHasKey('fullName', $data);
        $this->assertArrayHasKey('roles', $data);
        $this->assertArrayHasKey('active', $data);
        $this->assertArrayHasKey('emailVerified', $data);
        $this->assertArrayHasKey('createdAt', $data);
    }

    public function testGetMeUnauthenticated(): void
    {
        $this->client->request('GET', '/api/v1/me', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ]);

        $this->assertResponseStatusCodeSame(401);
    }

    public function testGetMeReturnsCorrectData(): void
    {
        $this->login('admin@localhost.com');

        $data = $this->apiGet('/api/v1/me');

        $this->assertResponseStatusCodeSame(200);
        $this->assertSame('admin@localhost.com', $data['email']);
        $this->assertContains('ROLE_ADMIN', $data['roles']);
    }
}
