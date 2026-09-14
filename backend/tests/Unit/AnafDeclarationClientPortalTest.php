<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\Anaf\AnafRateLimiter;
use App\Service\Declaration\AnafDeclarationClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** StareD112 lives on three hosts; when one is down the next answers, and the counter number goes as ghiseu=Y. */
final class AnafDeclarationClientPortalTest extends TestCase
{
    public function testStatusFallsBackToTheNextHost(): void
    {
        $calls = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$calls) {
            $calls[] = $url . ' ' . ($options['body'] ?? '');
            if (str_contains($url, 'www.anaf.ro')) {
                return new MockResponse('Service Unavailable', ['http_code' => 503]);
            }

            return new MockResponse('<html><body>Documentul este valid</body></html>', ['http_code' => 200]);
        });
        $client = new AnafDeclarationClient($http, $this->createMock(AnafRateLimiter::class));

        $r = $client->checkPortalStatus('INTERNT-123456789-2026', '12345678', true);
        self::assertSame('ok', $r['stare']);
        self::assertSame('https://stare.anaf.ro/StareD112', $r['host']);
        self::assertCount(2, $calls);
        self::assertStringContainsString('ghiseu=Y', $calls[1]);
        self::assertStringContainsString('id=123456789', $calls[1]);
    }

    public function testEveryHostDownIsOneError(): void
    {
        $http = new MockHttpClient(fn () => new MockResponse('', ['http_code' => 502]));
        $client = new AnafDeclarationClient($http, $this->createMock(AnafRateLimiter::class));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('every host');
        $client->checkPortalStatus('1', '12345678');
    }

    public function testRecipisaFromTheFirstHostThatHasIt(): void
    {
        $http = new MockHttpClient(function (string $method, string $url) {
            if (str_contains($url, 'www.anaf.ro')) {
                return new MockResponse('<html>not yet</html>', ['http_code' => 200]);
            }
            if (str_contains($url, 'stare.anaf.ro')) {
                return new MockResponse('%PDF-1.4 recipisa', ['http_code' => 200]);
            }

            return new MockResponse('', ['http_code' => 500]);
        });
        $client = new AnafDeclarationClient($http, $this->createMock(AnafRateLimiter::class));
        self::assertStringStartsWith('%PDF', $client->downloadPortalRecipisa('123'));
    }
}
