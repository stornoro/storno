<?php

namespace App\Tests\Unit;

use App\Service\TurnstileVerifier;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class TurnstileVerifierTest extends TestCase
{
    private function makeVerifier(?string $secret, string $env): TurnstileVerifier
    {
        $http = $this->createMock(HttpClientInterface::class);
        $http->expects($this->never())->method('request');

        return new TurnstileVerifier($http, new NullLogger(), $secret, $env);
    }

    public function testMissingSecretFailsClosedInProd(): void
    {
        self::assertFalse($this->makeVerifier(null, 'prod')->verify('token'));
        self::assertFalse($this->makeVerifier('', 'prod')->verify('token'));
    }

    public function testMissingSecretStaysOpenOutsideProd(): void
    {
        self::assertTrue($this->makeVerifier(null, 'dev')->verify(''));
        self::assertTrue($this->makeVerifier(null, 'test')->verify(''));
    }

    public function testEmptyTokenIsRejectedInProdWithSecret(): void
    {
        self::assertFalse($this->makeVerifier('secret', 'prod')->verify(''));
    }
}
