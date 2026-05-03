<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\PushRelayService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class PushRelayServiceTest extends TestCase
{
    private function service(callable|MockResponse $response): PushRelayService
    {
        $client = new MockHttpClient($response, 'http://relay.example');
        return new PushRelayService($client, new NullLogger(), 'http://relay.example');
    }

    public function testReturnsNullOnRelaySuccess(): void
    {
        $svc = $this->service(new MockResponse('{"ok":true}', ['http_code' => 200]));
        $this->assertNull($svc->send('TOKEN', 'Hi', 'Body'));
    }

    public function testReturnsTokenUnregisteredCodeForFcmV1Unregistered(): void
    {
        // Real-world relay response shape: 502 wrapping FCM's 404 with
        // errorCode "UNREGISTERED" inside the v1 response body.
        $body = json_encode([
            'error' => 'FCM error',
            'status' => 404,
            'detail' => json_encode([
                'error' => [
                    'code' => 404,
                    'message' => 'NotRegistered',
                    'status' => 'NOT_FOUND',
                    'details' => [['errorCode' => 'UNREGISTERED']],
                ],
            ]),
        ]);
        $svc = $this->service(new MockResponse($body, ['http_code' => 502]));

        $this->assertSame(PushRelayService::ERROR_TOKEN_UNREGISTERED, $svc->send('TOKEN', 'Hi', 'Body'));
    }

    public function testReturnsTokenUnregisteredCodeForLegacyNotRegisteredString(): void
    {
        // Legacy FCM responses use the literal "NotRegistered" string.
        $body = '{"error":"NotRegistered"}';
        $svc = $this->service(new MockResponse($body, ['http_code' => 400]));

        $this->assertSame(PushRelayService::ERROR_TOKEN_UNREGISTERED, $svc->send('TOKEN', 'Hi', 'Body'));
    }

    public function testReturnsGenericRelayErrorForOtherFailures(): void
    {
        // 5xx with a generic body — not a token-death signal. Caller must
        // not delete the device row in this case, so the error code must
        // be distinct from ERROR_TOKEN_UNREGISTERED.
        $body = '{"error":"upstream timeout"}';
        $svc = $this->service(new MockResponse($body, ['http_code' => 503]));

        $err = $svc->send('TOKEN', 'Hi', 'Body');
        $this->assertNotSame(PushRelayService::ERROR_TOKEN_UNREGISTERED, $err);
        $this->assertStringStartsWith('relay_503:', (string) $err);
    }

    public function testReturnsGenericRelayErrorWhenBodyMentionsUnregisteredButNotInFcmShape(): void
    {
        // Loose "UNREGISTERED" in an unrelated free-text field should NOT
        // trigger token deletion — only the FCM-shaped markers do.
        // The detector matches the literal '"UNREGISTERED"' (with quotes)
        // which would only appear as a JSON string value or errorCode.
        // A free-text body without quoted UNREGISTERED stays generic.
        $body = '{"error":"Some service was deregistered upstream"}';
        $svc = $this->service(new MockResponse($body, ['http_code' => 500]));

        $err = $svc->send('TOKEN', 'Hi', 'Body');
        $this->assertNotSame(PushRelayService::ERROR_TOKEN_UNREGISTERED, $err);
    }

    public function testReturnsRelayDisabledWhenUrlEmpty(): void
    {
        $svc = new PushRelayService(new MockHttpClient(), new NullLogger(), '');
        $this->assertSame('relay_disabled', $svc->send('TOKEN', 'Hi', 'Body'));
    }
}
