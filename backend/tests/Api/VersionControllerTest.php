<?php

declare(strict_types=1);

namespace App\Tests\Api;

class VersionControllerTest extends ApiTestCase
{
    public function testReturnsBaselinePayloadWithoutPlatform(): void
    {
        $this->client->request('GET', '/api/v1/version');

        $this->assertResponseStatusCodeSame(200);
        $data = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('version', $data);
        $this->assertArrayHasKey('web', $data);
        $this->assertArrayHasKey('mobile', $data);
        $this->assertArrayNotHasKey('gate', $data);
        $this->assertArrayNotHasKey('client', $data);
    }

    public function testIncludesGateWhenPlatformAndVersionProvided(): void
    {
        $this->client->request('GET', '/api/v1/version', [
            'platform' => 'ios',
            'version' => '0.0.1',
        ]);

        $this->assertResponseStatusCodeSame(200);
        $data = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertSame('ios', $data['platform']);
        $this->assertArrayHasKey('client', $data);
        $this->assertArrayHasKey('gate', $data);
        $this->assertSame('blocking', $data['gate']['tier']);
    }

    public function testReadsClientVersionFromHeader(): void
    {
        $this->client->request('GET', '/api/v1/version', [
            'platform' => 'android',
        ], [], [
            'HTTP_X-App-Version' => '0.0.1',
        ]);

        $this->assertResponseStatusCodeSame(200);
        $data = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertSame('blocking', $data['gate']['tier']);
    }

    public function testReturnsUnknownTierWithPlatformButNoVersion(): void
    {
        $this->client->request('GET', '/api/v1/version', ['platform' => 'ios']);

        $this->assertResponseStatusCodeSame(200);
        $data = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertSame('unknown', $data['gate']['tier']);
    }

    public function testIgnoresUnknownPlatform(): void
    {
        $this->client->request('GET', '/api/v1/version', [
            'platform' => 'blackberry',
            'version' => '1.0.0',
        ]);

        $this->assertResponseStatusCodeSame(200);
        $data = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertArrayNotHasKey('gate', $data);
        $this->assertArrayNotHasKey('client', $data);
    }
}
