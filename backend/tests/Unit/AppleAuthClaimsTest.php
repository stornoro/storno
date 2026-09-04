<?php

namespace App\Tests\Unit;

use App\Controller\Api\Auth\AppleAuthController;
use App\Service\LicenseValidationService;
use App\Service\MfaService;
use Doctrine\ORM\EntityManagerInterface;
use Gesdinet\JWTRefreshTokenBundle\Generator\RefreshTokenGeneratorInterface;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * An Apple identity token minted for another app must not log anyone in here.
 */
class AppleAuthClaimsTest extends TestCase
{
    private function makeController(?string $clientIds): AppleAuthController
    {
        return new AppleAuthController(
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(JWTTokenManagerInterface::class),
            $this->createMock(RefreshTokenGeneratorInterface::class),
            $this->createMock(RefreshTokenManagerInterface::class),
            $this->createMock(SluggerInterface::class),
            $this->createMock(LicenseValidationService::class),
            $this->createMock(MfaService::class),
            $clientIds,
        );
    }

    private function assertClaims(AppleAuthController $controller, array $claims, ?string $nonce = null): void
    {
        $method = new \ReflectionMethod($controller, 'assertAudienceAndNonce');
        $method->invoke($controller, $claims, $nonce);
    }

    public function testDefaultAudienceIsTheAppBundleId(): void
    {
        $this->assertClaims($this->makeController(null), ['aud' => 'com.storno.app']);
        self::assertTrue(true);
    }

    public function testConfiguredAudiencesAreAccepted(): void
    {
        $controller = $this->makeController('com.storno.app, ro.storno.web');
        $this->assertClaims($controller, ['aud' => 'ro.storno.web']);
        $this->assertClaims($controller, ['aud' => ['other', 'com.storno.app']]);
        self::assertTrue(true);
    }

    public function testForeignAudienceIsRejected(): void
    {
        $this->expectExceptionMessage('Invalid audience.');
        $this->assertClaims($this->makeController(null), ['aud' => 'com.attacker.app']);
    }

    public function testMissingAudienceIsRejected(): void
    {
        $this->expectExceptionMessage('Invalid audience.');
        $this->assertClaims($this->makeController(null), ['sub' => 'x']);
    }

    public function testNonceIsVerifiedWhenClientSendsOne(): void
    {
        $controller = $this->makeController(null);

        $this->assertClaims($controller, ['aud' => 'com.storno.app', 'nonce' => 'raw'], 'raw');
        $this->assertClaims($controller, ['aud' => 'com.storno.app', 'nonce' => hash('sha256', 'raw')], 'raw');
        // No nonce sent by the client: claim is not enforced
        $this->assertClaims($controller, ['aud' => 'com.storno.app', 'nonce' => 'whatever'], null);
        self::assertTrue(true);

        $this->expectExceptionMessage('Invalid nonce.');
        $this->assertClaims($controller, ['aud' => 'com.storno.app', 'nonce' => 'other'], 'raw');
    }
}
