<?php

declare(strict_types=1);

namespace App\Tests\Api;

class MobileVersionGateTest extends ApiTestCase
{
    public function testBlocksBelowMinClientWith426(): void
    {
        $this->client->request('GET', '/api/v1/companies', [], [], [
            'HTTP_X-Platform' => 'ios',
            'HTTP_X-App-Version' => '0.0.1',
        ]);

        $this->assertResponseStatusCodeSame(426);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('blocking', $data['tier']);
        $this->assertNotEmpty($data['storeUrl']);
        $this->assertNotEmpty($data['min']);
    }

    public function testLetsAtMinClientThrough(): void
    {
        $this->client->request('GET', '/api/v1/companies', [], [], [
            'HTTP_X-Platform' => 'ios',
            'HTTP_X-App-Version' => '99.99.99',
        ]);

        // The subscriber must not return 426. Whatever else the controller
        // does (401 because we have no auth, 200, etc.) is fine — we just
        // assert the gate did not short-circuit.
        $this->assertNotSame(426, $this->client->getResponse()->getStatusCode());
    }

    public function testVersionEndpointIsAllowlistedEvenWhenBelowMin(): void
    {
        $this->client->request('GET', '/api/v1/version?platform=ios', [], [], [
            'HTTP_X-Platform' => 'ios',
            'HTTP_X-App-Version' => '0.0.1',
        ]);

        $this->assertResponseStatusCodeSame(200);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('blocking', $data['gate']['tier']);
    }

    public function testAuthEndpointIsAllowlistedEvenWhenBelowMin(): void
    {
        $this->client->request('POST', '/api/auth', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-Platform' => 'ios',
            'HTTP_X-App-Version' => '0.0.1',
        ], json_encode(['email' => 'admin@localhost.com', 'password' => 'password']));

        // 200 for valid creds. Critically — not 426. The user must be able
        // to authenticate after updating.
        $this->assertNotSame(426, $this->client->getResponse()->getStatusCode());
    }

    public function testWebTrafficWithoutPlatformHeaderPassesThrough(): void
    {
        // No X-Platform header → web/desktop/CLI client → never blocked.
        $this->client->request('GET', '/api/v1/companies', [], [], [
            'HTTP_X-App-Version' => '0.0.1',
        ]);
        $this->assertNotSame(426, $this->client->getResponse()->getStatusCode());
    }

    public function testMissingVersionHeaderPassesThrough(): void
    {
        // Platform header set but version not — older builds may not send
        // X-App-Version; we cannot determine the tier so we let them pass.
        $this->client->request('GET', '/api/v1/companies', [], [], [
            'HTTP_X-Platform' => 'ios',
        ]);
        $this->assertNotSame(426, $this->client->getResponse()->getStatusCode());
    }

    public function testUnknownPlatformValuePassesThrough(): void
    {
        // We only manage ios/android/huawei. Anything else (including web,
        // robots, custom integrations) is not gated.
        $this->client->request('GET', '/api/v1/companies', [], [], [
            'HTTP_X-Platform' => 'desktop',
            'HTTP_X-App-Version' => '0.0.1',
        ]);
        $this->assertNotSame(426, $this->client->getResponse()->getStatusCode());
    }
}
