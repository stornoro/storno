<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\Marketing\RegistrationAttribution;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class RegistrationAttributionTest extends TestCase
{
    public function testUtmAndLandingAreKeptAndClickIdsAreNotStored(): void
    {
        $request = Request::create('/api/auth/register', 'POST', server: ['HTTP_REFERER' => 'https://app.storno.ro/register']);
        $payload = ['attribution' => [
            'fbc' => 'fb.1.1700000000000.AbC-123',
            'fbp' => 'fb.1.1700000000000.123456',
            'utm' => ['utm_source' => 'facebook', 'utm_campaign' => 'tofu-gratuit', 'landing' => 'https://storno.ro/', 'junk' => 'x', 'utm_term' => "bad\nvalue"],
        ]];

        $attribution = RegistrationAttribution::fromRequest($request, $payload);
        self::assertSame('fb.1.1700000000000.AbC-123', $attribution['fbc']);
        self::assertSame(['utm_source' => 'facebook', 'utm_campaign' => 'tofu-gratuit', 'landing' => 'https://storno.ro/'], $attribution['utm']);

        $stored = RegistrationAttribution::forStorage($attribution);
        self::assertSame([
            'utm_source' => 'facebook',
            'utm_campaign' => 'tofu-gratuit',
            'landing' => 'https://storno.ro/',
            'meta_click' => true,
            'referrer' => 'https://app.storno.ro/register',
            'source_url' => 'https://app.storno.ro/register',
        ], $stored);
        self::assertArrayNotHasKey('fbc', $stored);
    }

    public function testOrganicSignupStoresNothing(): void
    {
        $attribution = RegistrationAttribution::fromRequest(Request::create('/api/auth/register', 'POST'), []);
        self::assertNull(RegistrationAttribution::forStorage($attribution));
    }
}
