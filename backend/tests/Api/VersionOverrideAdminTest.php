<?php

declare(strict_types=1);

namespace App\Tests\Api;

class VersionOverrideAdminTest extends ApiTestCase
{
    public function testListReturnsEveryPlatformWithDefaultsAndEffective(): void
    {
        $this->login('superadmin@storno.ro');

        $data = $this->apiGet('/api/v1/admin/version-overrides');

        $this->assertArrayHasKey('platforms', $data);
        $platforms = array_column($data['platforms'], 'platform');
        $this->assertContains('ios', $platforms);
        $this->assertContains('android', $platforms);
        $this->assertContains('huawei', $platforms);

        foreach ($data['platforms'] as $row) {
            $this->assertArrayHasKey('defaults', $row);
            $this->assertArrayHasKey('effective', $row);
            $this->assertArrayHasKey('override', $row); // null until something is set
            $this->assertArrayHasKey('min', $row['defaults']);
            $this->assertArrayHasKey('latest', $row['defaults']);
        }
    }

    public function testPutSetsOverrideAndIsReflectedInList(): void
    {
        $this->login('superadmin@storno.ro');

        $put = $this->apiPut('/api/v1/admin/version-overrides/ios', [
            'minOverride' => '1.4.5',
            'messageOverride' => ['ro' => 'Actualizare critica.', 'en' => 'Critical update.'],
        ]);

        $this->assertSame('ios', $put['platform']);
        $this->assertSame('1.4.5', $put['override']['minOverride']);
        $this->assertSame('Actualizare critica.', $put['override']['messageOverride']['ro']);
        $this->assertTrue($put['override']['hasOverride']);

        // Subsequent list call surfaces the override on the effective row.
        $list = $this->apiGet('/api/v1/admin/version-overrides');
        $iosRow = current(array_filter($list['platforms'], fn ($r) => $r['platform'] === 'ios'));
        $this->assertSame('1.4.5', $iosRow['effective']['min']);
        $this->assertSame('Actualizare critica.', $iosRow['effective']['message']['ro']);

        // Reset for downstream tests.
        $this->apiPut('/api/v1/admin/version-overrides/ios', [
            'minOverride' => null,
            'messageOverride' => null,
        ]);
    }

    public function testPutWithNullClearsTheOverride(): void
    {
        $this->login('superadmin@storno.ro');

        // Set then clear.
        $this->apiPut('/api/v1/admin/version-overrides/android', ['minOverride' => '9.9.9']);
        $cleared = $this->apiPut('/api/v1/admin/version-overrides/android', ['minOverride' => null]);

        $this->assertNull($cleared['override']['minOverride']);
        $this->assertFalse($cleared['override']['hasOverride']);
    }

    public function testRejectsUnknownPlatform(): void
    {
        $this->login('superadmin@storno.ro');
        $this->client->request('PUT', '/api/v1/admin/version-overrides/blackberry', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token,
        ], json_encode(['minOverride' => '1.0.0']));

        // Symfony's route requirement returns 404 when the URL pattern does
        // not match — that's the expected guard against accidental writes.
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testNonAdminCannotWriteOverride(): void
    {
        // user@localhost.com is created by UserFixtures with ROLE_USER only.
        $this->login('user@localhost.com', 'password');

        $this->client->request('PUT', '/api/v1/admin/version-overrides/ios', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token,
        ], json_encode(['minOverride' => '1.0.0']));

        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testGateEndpointReflectsLiveOverride(): void
    {
        $this->login('superadmin@storno.ro');

        // Push min to a value above any plausible client → blocking.
        $this->apiPut('/api/v1/admin/version-overrides/huawei', [
            'minOverride' => '99.99.99',
        ]);

        $this->client->request('GET', '/api/v1/version', [
            'platform' => 'huawei',
            'version' => '1.0.0',
        ]);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('blocking', $data['gate']['tier']);
        $this->assertSame('99.99.99', $data['gate']['min']);

        // Reset.
        $this->apiPut('/api/v1/admin/version-overrides/huawei', ['minOverride' => null]);
    }
}
