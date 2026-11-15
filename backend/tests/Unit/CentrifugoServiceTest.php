<?php

namespace App\Tests\Unit;

use App\Service\Centrifugo\CentrifugoService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The wire contract with the realtime server: one POST per API method under
 * /api, authenticated with X-API-Key, and HS256 tokens for clients. The live
 * counterpart is deploy/tests/centrifugo, which runs against a real server.
 */
class CentrifugoServiceTest extends TestCase
{
    private const SECRET = 'unit-secret';

    /** @var list<array{method: string, url: string, headers: array<string, string>, body: array<string, mixed>}> */
    private array $requests = [];

    private function service(string|\Closure $reply = '{"result":{}}'): CentrifugoService
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($reply): MockResponse {
            $headers = [];
            foreach ($options['headers'] as $line) {
                [$name, $value] = explode(': ', $line, 2);
                $headers[strtolower($name)] = $value;
            }
            $this->requests[] = [
                'method' => $method,
                'url' => $url,
                'headers' => $headers,
                'body' => json_decode($options['body'], true),
            ];

            return $reply instanceof \Closure ? $reply() : new MockResponse($reply);
        });

        return new CentrifugoService($client, new NullLogger(), 'http://realtime:8000/api', 'api-key', self::SECRET);
    }

    public function testPublishPostsToThePublishMethodWithTheApiKey(): void
    {
        $this->service()->publish('invoices:company_1', ['type' => 'updated']);

        $this->assertCount(1, $this->requests);
        $request = $this->requests[0];
        $this->assertSame('POST', $request['method']);
        $this->assertSame('http://realtime:8000/api/publish', $request['url']);
        $this->assertSame('api-key', $request['headers']['x-api-key']);
        $this->assertSame('application/json', $request['headers']['content-type']);
        $this->assertSame(['channel' => 'invoices:company_1', 'data' => ['type' => 'updated']], $request['body']);
    }

    public function testBroadcastSendsAllChannelsInOneCall(): void
    {
        $this->service()->broadcast(['a:1', 'b:1'], ['n' => 1]);

        $this->assertSame('http://realtime:8000/api/broadcast', $this->requests[0]['url']);
        $this->assertSame(['channels' => ['a:1', 'b:1'], 'data' => ['n' => 1]], $this->requests[0]['body']);
    }

    public function testBroadcastWithoutChannelsSendsNothing(): void
    {
        $this->assertSame([], $this->service()->broadcast([], ['n' => 1]));
        $this->assertSame([], $this->requests);
    }

    public function testBatchWrapsCommandsAndReturnsTheReplies(): void
    {
        $service = $this->service('{"replies":[{"publish":{}},{"broadcast":{"responses":[]}}]}');

        $replies = $service->batch([
            ['publish' => ['channel' => 'a:1', 'data' => ['n' => 1]]],
            ['broadcast' => ['channels' => ['a:1', 'b:1'], 'data' => ['n' => 2]]],
        ]);

        $this->assertCount(2, $replies);
        $this->assertSame('http://realtime:8000/api/batch', $this->requests[0]['url']);
        $this->assertTrue($this->requests[0]['body']['parallel']);
        $this->assertSame(['publish', 'broadcast'], array_map(static fn (array $c) => array_key_first($c), $this->requests[0]['body']['commands']));
    }

    public function testFlushSendsASingleQueuedPublishWithoutTheBatchEnvelope(): void
    {
        $service = $this->service();
        $service->queue('a:1', ['n' => 1]);
        $service->flush();

        $this->assertSame(0, $service->getBufferSize());
        $this->assertSame('http://realtime:8000/api/publish', $this->requests[0]['url']);
    }

    public function testFlushBatchesSeveralQueuedCommandsIntoOneRequest(): void
    {
        $service = $this->service('{"replies":[{},{}]}');
        $service->queue('a:1', ['n' => 1]);
        $service->queueBroadcast(['a:1', 'b:1'], ['n' => 2]);
        $service->queueBroadcast([], ['ignored' => true]);
        $this->assertSame(2, $service->getBufferSize());

        $service->flush();
        $service->flush();

        $this->assertCount(1, $this->requests);
        $this->assertSame('http://realtime:8000/api/batch', $this->requests[0]['url']);
        $this->assertCount(2, $this->requests[0]['body']['commands']);
    }

    public function testAnUnreachableServerNeverBreaksTheCaller(): void
    {
        $service = $this->service(static fn () => new MockResponse('', ['http_code' => 503]));

        $this->assertArrayHasKey('error', $service->publish('a:1', []));
        $this->assertSame([], $service->batch([['publish' => ['channel' => 'a:1', 'data' => []]]]));
    }

    public function testConnectionTokenIsAnHs256JwtForTheUser(): void
    {
        $expires = time() + 3600;
        $token = $this->service()->generateConnectionToken('user-1', $expires, ['email' => 'user@example.com']);

        [$header, $claims] = $this->decodeAndVerify($token);
        $this->assertSame(['typ' => 'JWT', 'alg' => 'HS256'], $header);
        $this->assertSame('user-1', $claims['sub']);
        $this->assertSame($expires, $claims['exp']);
        $this->assertSame(['email' => 'user@example.com'], $claims['info']);
        $this->assertArrayNotHasKey('channel', $claims);
        $this->assertEqualsWithDelta(time(), $claims['iat'], 5);
    }

    public function testSubscriptionTokenIsBoundToOneChannel(): void
    {
        $token = $this->service()->generateSubscriptionToken('user-1', 'invoices:company_1', time() + 60);

        [, $claims] = $this->decodeAndVerify($token);
        $this->assertSame('user-1', $claims['sub']);
        $this->assertSame('invoices:company_1', $claims['channel']);
    }

    public function testTokenWithoutExpiryCarriesNoExpClaim(): void
    {
        [, $claims] = $this->decodeAndVerify($this->service()->generateConnectionToken('user-1'));

        $this->assertArrayNotHasKey('exp', $claims);
        $this->assertArrayNotHasKey('info', $claims);
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private function decodeAndVerify(string $token): array
    {
        $parts = explode('.', $token);
        $this->assertCount(3, $parts);
        $this->assertDoesNotMatchRegularExpression('/[+\/=]/', $token, 'token must be base64url without padding');

        $decode = static fn (string $part): string => base64_decode(strtr($part, '-_', '+/'));
        $this->assertTrue(hash_equals(
            hash_hmac('sha256', $parts[0] . '.' . $parts[1], self::SECRET, true),
            $decode($parts[2]),
        ), 'signature must be HMAC-SHA256 of header.payload');

        return [json_decode($decode($parts[0]), true), json_decode($decode($parts[1]), true)];
    }
}
