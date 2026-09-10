<?php

namespace App\Tests\Unit;

use App\Service\AppVersion\MobileLatestVersionResolver;
use PHPUnit\Framework\TestCase;

class MobileLatestVersionResolverTest extends TestCase
{
    private MobileLatestVersionResolver $r;

    protected function setUp(): void
    {
        $this->r = new MobileLatestVersionResolver();
    }

    public function testStoreVersionConfirmedByDevicesIsAdopted(): void
    {
        self::assertSame('1.5.2', $this->r->resolve('1.5.2', '1.5.2', '1.1.11', '1.0.0')['latest']);
    }

    public function testStoreLabelAheadOfBinaryIsNotTrusted(): void
    {
        // App Store Connect says 1.5.1 but every installed app reports 1.1.11
        $d = $this->r->resolve('1.5.1', '1.1.11', '1.1.2', '1.0.0');
        self::assertSame('1.1.11', $d['latest']);
        self::assertStringContainsString('waiting for a device on 1.5.1', $d['reason']);
    }

    public function testNewStoreVersionWaitsForFirstDevice(): void
    {
        self::assertNull($this->r->resolve('1.5.2', '1.1.11', '1.1.11', '1.0.0')['latest']);
        self::assertNull($this->r->resolve('1.5.2', null, '1.1.11', '1.0.0')['latest']);
    }

    public function testNoChangeWhenAlreadyCurrent(): void
    {
        self::assertNull($this->r->resolve('1.1.11', '1.1.11', '1.1.11', '1.0.0')['latest']);
        self::assertNull($this->r->resolve('1.1.9', '1.1.9', '1.1.11', '1.0.0')['latest']);
    }

    public function testStoreUnavailableFallsBackToDevices(): void
    {
        self::assertSame('1.2.0', $this->r->resolve(null, '1.2.0', '1.1.11', '1.0.0')['latest']);
    }

    public function testNormalizeStripsBuildSuffixes(): void
    {
        self::assertSame('1.1.11', $this->r->normalize('1.1.11 (33)'));
        self::assertSame('1.5.0', $this->r->normalize('v1.5'));
        self::assertNull($this->r->normalize('latest'));
    }
}
