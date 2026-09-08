<?php

namespace App\Tests\Unit;

use App\Service\Marketing\MetaConversionsApi;
use App\Service\Marketing\RegistrationAttribution;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;

class MetaConversionsApiTest extends TestCase
{
    public function testDisabledWithoutCredentialsAndMakesNoCall(): void
    {
        $client = new MockHttpClient(function () {
            $this->fail('No HTTP call expected when CAPI is not configured');
        });
        $api = new MetaConversionsApi($client, new NullLogger(), null, null);

        $this->assertFalse($api->isEnabled());
        $this->assertFalse($api->send(['event_name' => 'CompleteRegistration', 'event_id' => 'x', 'event_time' => 1]));
    }

    public function testBuildEventHashesPiiAndDropsEmptyFields(): void
    {
        $api = new MetaConversionsApi(new MockHttpClient(), new NullLogger(), '123', 'tok');

        $event = $api->buildEvent([
            'event_name' => 'CompleteRegistration',
            'event_id' => 'user-uuid',
            'event_time' => 1700000000,
            'event_source_url' => 'https://app.storno.ro/register',
            'email' => '  Ion.Popescu@Example.com ',
            'external_id' => 'user-uuid',
            'client_ip' => '203.0.113.7',
            'client_user_agent' => 'Mozilla/5.0',
            'fbc' => 'fb.1.1700000000000.AbC123',
            'fbp' => null,
        ]);

        $this->assertSame('website', $event['action_source']);
        $this->assertSame([hash('sha256', 'ion.popescu@example.com')], $event['user_data']['em']);
        $this->assertSame([hash('sha256', 'user-uuid')], $event['user_data']['external_id']);
        $this->assertSame('fb.1.1700000000000.AbC123', $event['user_data']['fbc']);
        $this->assertArrayNotHasKey('fbp', $event['user_data']);
        $this->assertStringNotContainsString('Popescu', json_encode($event));
    }

    public function testSendPostsToPixelEndpointWithTokenAndTestCode(): void
    {
        $captured = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured) {
            $captured = ['method' => $method, 'url' => $url, 'body' => json_decode($options['body'], true)];

            return new MockResponse(json_encode(['events_received' => 1]));
        });
        $api = new MetaConversionsApi($client, new NullLogger(), '1598365005105912', 'secret-token', 'TEST123');

        $ok = $api->send(['event_name' => 'CompleteRegistration', 'event_id' => 'e1', 'event_time' => 1700000000, 'email' => 'a@b.ro']);

        $this->assertTrue($ok);
        $this->assertSame('POST', $captured['method']);
        $this->assertStringStartsWith('https://graph.facebook.com/v21.0/1598365005105912/events?access_token=secret-token', $captured['url']);
        $this->assertSame('TEST123', $captured['body']['test_event_code']);
        $this->assertSame('CompleteRegistration', $captured['body']['data'][0]['event_name']);
    }

    public function testSendReturnsFalseOnGraphError(): void
    {
        $client = new MockHttpClient(new MockResponse(json_encode(['error' => ['message' => 'Invalid OAuth access token']]), ['http_code' => 400]));
        $api = new MetaConversionsApi($client, new NullLogger(), '1', 'bad');

        $this->assertFalse($api->send(['event_name' => 'CompleteRegistration', 'event_id' => 'e1', 'event_time' => 1]));
    }

    public function testAttributionPrefersPayloadValidatesFormatAndFallsBackToCookies(): void
    {
        $request = Request::create('/api/auth/register', 'POST', server: ['HTTP_REFERER' => 'https://app.storno.ro/register'], cookies: ['_fbc' => 'fb.1.1700000000000.CookieClick', '_fbp' => 'fb.1.1700000000000.987654321']);

        $a = RegistrationAttribution::fromRequest($request, ['attribution' => ['fbc' => 'fb.1.1700000000000.PayloadClick', 'fbp' => 'not-valid', 'source_url' => 'https://storno.ro/sincronizare-efactura']]);
        $this->assertSame('fb.1.1700000000000.PayloadClick', $a['fbc']);
        $this->assertSame('fb.1.1700000000000.987654321', $a['fbp'], 'invalid payload fbp falls back to the cookie');
        $this->assertSame('https://storno.ro/sincronizare-efactura', $a['source_url']);

        $b = RegistrationAttribution::fromRequest(Request::create('/api/auth/register', 'POST'), ['attribution' => ['fbclid' => 'IwAR0abc_DEF-123']]);
        $this->assertMatchesRegularExpression('/^fb\.1\.\d{13}\.IwAR0abc_DEF-123$/', $b['fbc']);
        $this->assertNull($b['fbp']);
        $this->assertNull($b['source_url']);

        $c = RegistrationAttribution::fromRequest(Request::create('/api/auth/register', 'POST'), ['attribution' => ['fbclid' => 'bad value with spaces', 'fbc' => 'javascript:alert(1)']]);
        $this->assertNull($c['fbc']);
    }
}
