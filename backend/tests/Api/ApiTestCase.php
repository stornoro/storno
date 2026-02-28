<?php

namespace App\Tests\Api;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

abstract class ApiTestCase extends WebTestCase
{
    protected KernelBrowser $client;
    protected ?string $token = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    protected function login(string $email = 'admin@localhost.com', string $password = 'password'): string
    {
        $this->client->request('POST', '/api/auth', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['email' => $email, 'password' => $password]));

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('token', $data);
        $this->token = $data['token'];

        return $this->token;
    }

    protected function apiGet(string $uri, array $headers = []): array
    {
        $this->client->request('GET', $uri, [], [], $this->buildHeaders($headers));

        return $this->decodeResponse();
    }

    protected function apiPost(string $uri, array $body = [], array $headers = []): array
    {
        $this->client->request('POST', $uri, [], [], $this->buildHeaders($headers), json_encode($body));

        return $this->decodeResponse();
    }

    protected function apiPut(string $uri, array $body = [], array $headers = []): array
    {
        $this->client->request('PUT', $uri, [], [], $this->buildHeaders($headers), json_encode($body));

        return $this->decodeResponse();
    }

    protected function apiPatch(string $uri, array $body = [], array $headers = []): array
    {
        $this->client->request('PATCH', $uri, [], [], $this->buildHeaders($headers), json_encode($body));

        return $this->decodeResponse();
    }

    protected function apiDelete(string $uri, array $headers = []): array
    {
        $this->client->request('DELETE', $uri, [], [], $this->buildHeaders($headers));

        return $this->decodeResponse();
    }

    protected function buildHeaders(array $extra = []): array
    {
        $headers = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ];

        if ($this->token) {
            $headers['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->token;
        }

        foreach ($extra as $key => $value) {
            $headers['HTTP_' . str_replace('-', '_', strtoupper($key))] = $value;
        }

        return $headers;
    }

    protected function decodeResponse(): array
    {
        $content = $this->client->getResponse()->getContent();
        if (empty($content)) {
            return [];
        }

        return json_decode($content, true) ?? [];
    }

    protected function getFirstCompanyId(): string
    {
        $data = $this->apiGet('/api/v1/companies');
        $this->assertNotEmpty($data['data'], 'No companies found');

        return $data['data'][0]['id'];
    }
}
