<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\VersionGateService;
use PHPUnit\Framework\TestCase;

class VersionGateServiceTest extends TestCase
{
    private function service(array $extras = []): VersionGateService
    {
        return new VersionGateService([
            'mobile' => [
                'ios' => array_merge([
                    'latest' => '1.5.0',
                    'min' => '1.2.0',
                    'storeUrl' => 'https://apple/app',
                ], $extras['ios'] ?? []),
                'android' => array_merge([
                    'latest' => '1.5.0',
                    'min' => '1.2.0',
                    'storeUrl' => 'https://play/app',
                ], $extras['android'] ?? []),
            ],
        ]);
    }

    public function testReturnsBlockingWhenClientBelowMin(): void
    {
        $result = $this->service()->evaluate('ios', '1.1.0');
        $this->assertSame(VersionGateService::TIER_BLOCKING, $result['tier']);
        $this->assertSame('1.5.0', $result['latest']);
        $this->assertSame('1.2.0', $result['min']);
    }

    public function testReturnsRecommendedWhenBetweenMinAndLatest(): void
    {
        $result = $this->service()->evaluate('ios', '1.3.0');
        $this->assertSame(VersionGateService::TIER_RECOMMENDED, $result['tier']);
    }

    public function testReturnsOkWhenAtLatest(): void
    {
        $result = $this->service()->evaluate('ios', '1.5.0');
        $this->assertSame(VersionGateService::TIER_OK, $result['tier']);
    }

    public function testReturnsOkWhenAheadOfLatest(): void
    {
        $result = $this->service()->evaluate('ios', '2.0.0');
        $this->assertSame(VersionGateService::TIER_OK, $result['tier']);
    }

    public function testReturnsUnknownWhenClientVersionMissing(): void
    {
        $result = $this->service()->evaluate('ios', null);
        $this->assertSame(VersionGateService::TIER_UNKNOWN, $result['tier']);
    }

    public function testHandlesDoubleDigitMinor(): void
    {
        // Lexicographic compare would call 1.10.0 < 1.9.0; version_compare must
        // place 1.10.0 above 1.9.0 (which is the lexical bug we explicitly
        // guard against).
        $svc = $this->service(['ios' => ['min' => '1.9.0', 'latest' => '1.10.0']]);
        $this->assertSame(VersionGateService::TIER_RECOMMENDED, $svc->evaluate('ios', '1.9.5')['tier']);
        $this->assertSame(VersionGateService::TIER_OK, $svc->evaluate('ios', '1.10.0')['tier']);
        $this->assertSame(VersionGateService::TIER_BLOCKING, $svc->evaluate('ios', '1.8.9')['tier']);
    }

    public function testStripsBuildMetadataBeforeComparing(): void
    {
        // 1.5.0+build.42 should be treated as 1.5.0 → ok (not blocking).
        $result = $this->service()->evaluate('ios', '1.5.0+build.42');
        $this->assertSame(VersionGateService::TIER_OK, $result['tier']);
    }

    public function testReturnsNullForUnknownPlatform(): void
    {
        $this->assertNull($this->service()->evaluate('blackberry', '1.0.0'));
    }

    public function testNormalizesEmptyMessageAndUrlToNull(): void
    {
        $svc = $this->service(['ios' => ['message' => [], 'releaseNotesUrl' => null]]);
        $result = $svc->evaluate('ios', '1.5.0');
        $this->assertNull($result['message']);
        $this->assertNull($result['releaseNotesUrl']);
    }

    public function testPropagatesLocalizedMessage(): void
    {
        $svc = $this->service(['ios' => [
            'message' => ['ro' => 'Actualizeaza acum.', 'en' => 'Update now.'],
            'releaseNotesUrl' => 'https://example/notes',
        ]]);
        $result = $svc->evaluate('ios', '1.0.0');
        $this->assertSame('Actualizeaza acum.', $result['message']['ro']);
        $this->assertSame('Update now.', $result['message']['en']);
        $this->assertSame('https://example/notes', $result['releaseNotesUrl']);
    }
}
