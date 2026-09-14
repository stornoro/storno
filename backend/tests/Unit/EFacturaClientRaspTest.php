<?php

namespace App\Tests\Unit;

use App\Service\Anaf\AnafRateLimiter;
use App\Service\Anaf\EFacturaClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class EFacturaClientRaspTest extends TestCase
{
    public function testMessageToIssuerIsPostedAsRaspHeader(): void
    {
        $captured = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured) {
            $captured = ['method' => $method, 'url' => $url, 'body' => $options['body'] ?? null, 'headers' => $options['headers'] ?? []];
            return new MockResponse('<?xml version="1.0"?><header xmlns="mfp:anaf:dgti:efactura:stareMesajFactura:v1" dateResponse="202609141200" ExecutionStatus="0" index_incarcare="5001234"/>');
        });
        $client = new EFacturaClient($http, $this->createMock(AnafRateLimiter::class), 'test');

        $res = $client->sendMessageToIssuer('4009876', 'Factura nu ne apartine: "CUI" gresit & marfa nelivrata', '12345678', 'tok');

        self::assertTrue($res->success);
        self::assertSame('5001234', $res->uploadId);
        self::assertSame('POST', $captured['method']);
        self::assertStringContainsString('/test/FCTEL/rest/upload?', $captured['url']);
        self::assertStringContainsString('standard=RASP', $captured['url']);
        self::assertStringContainsString('cif=12345678', $captured['url']);
        self::assertStringContainsString('<header xmlns="mfp:anaf:dgti:spv:reqMesaj:v1" message="Factura nu ne apartine: &quot;CUI&quot; gresit &amp; marfa nelivrata" index_incarcare="4009876"/>', $captured['body']);
        self::assertContains('Authorization: Bearer tok', $captured['headers']);
    }

    public function testRejectedMessageIsReportedNotThrown(): void
    {
        $http = new MockHttpClient(new MockResponse('<?xml version="1.0"?><header xmlns="mfp:anaf:dgti:efactura:stareMesajFactura:v1" ExecutionStatus="1"><Errors errorMessage="Indexul nu exista"/></header>'));
        $client = new EFacturaClient($http, $this->createMock(AnafRateLimiter::class), 'test');

        $res = $client->sendMessageToIssuer('1', 'test', '1', 'tok');

        self::assertFalse($res->success);
    }

    public function testEmptyOrOverlongMessageIsRefusedLocally(): void
    {
        $client = new EFacturaClient(new MockHttpClient(), $this->createMock(AnafRateLimiter::class), 'test');

        $this->expectException(\InvalidArgumentException::class);
        $client->sendMessageToIssuer('1', str_repeat('a', EFacturaClient::RASP_MAX_LENGTH + 1), '1', 'tok');
    }

    public function testNonNumericIndexIsRefusedLocally(): void
    {
        $client = new EFacturaClient(new MockHttpClient(), $this->createMock(AnafRateLimiter::class), 'test');

        $this->expectException(\InvalidArgumentException::class);
        $client->sendMessageToIssuer('abc"/>', 'hello', '1', 'tok');
    }
}
