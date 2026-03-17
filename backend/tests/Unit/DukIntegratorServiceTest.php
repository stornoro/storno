<?php

namespace App\Tests\Unit;

use App\Model\Declaration\DukValidationResult;
use App\Service\Declaration\DukIntegratorService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class DukIntegratorServiceTest extends TestCase
{
    private function createService(array $responses): DukIntegratorService
    {
        $httpClient = new MockHttpClient($responses);
        return new DukIntegratorService(
            logger: new NullLogger(),
            httpClient: $httpClient,
            javaServiceUrl: 'http://127.0.0.1:8082',
        );
    }

    public function testValidateReturnsValidResult(): void
    {
        $service = $this->createService([
            new MockResponse(json_encode([
                'valid' => true,
                'errors' => [],
                'warnings' => ['WARNING: minor issue'],
                'elapsed_ms' => 150,
            ])),
        ]);

        $result = $service->validate('<xml/>', 'D394');

        $this->assertInstanceOf(DukValidationResult::class, $result);
        $this->assertTrue($result->valid);
        $this->assertEmpty($result->errors);
        $this->assertCount(1, $result->warnings);
    }

    public function testValidateReturnsInvalidResult(): void
    {
        $service = $this->createService([
            new MockResponse(json_encode([
                'valid' => false,
                'errors' => ['Missing required field: an'],
                'warnings' => [],
            ])),
        ]);

        $result = $service->validate('<xml/>', 'D394');

        $this->assertFalse($result->valid);
        $this->assertCount(1, $result->errors);
        $this->assertSame('Missing required field: an', $result->errors[0]);
    }

    public function testValidateServiceUnavailableThrows(): void
    {
        $service = $this->createService([
            new MockResponse(
                json_encode(['error' => 'DUK validation unavailable']),
                ['http_code' => 503],
            ),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DUK validation service unavailable');
        $service->validate('<xml/>', 'D394');
    }

    public function testValidateHandles422Error(): void
    {
        $service = $this->createService([
            new MockResponse(
                json_encode(['error' => 'XML parsing failed']),
                ['http_code' => 422],
            ),
        ]);

        $result = $service->validate('<invalid>', 'D394');

        $this->assertFalse($result->valid);
        $this->assertContains('XML parsing failed', $result->errors);
    }

    public function testValidateTypeIsUppercased(): void
    {
        $requestedUrl = null;
        $httpClient = new MockHttpClient(function ($method, $url) use (&$requestedUrl) {
            $requestedUrl = $url;
            return new MockResponse(json_encode(['valid' => true, 'errors' => [], 'warnings' => []]));
        });
        $service = new DukIntegratorService(
            logger: new \Psr\Log\NullLogger(),
            httpClient: $httpClient,
            javaServiceUrl: 'http://127.0.0.1:8082',
        );

        $service->validate('<xml/>', 'd394');

        $this->assertNotNull($requestedUrl);
        $this->assertStringContainsString('type=D394', $requestedUrl);
    }

    public function testGeneratePdfReturnsBinary(): void
    {
        $pdfContent = '%PDF-1.4 fake pdf content';
        $service = $this->createService([
            new MockResponse($pdfContent, [
                'http_code' => 200,
                'response_headers' => ['Content-Type' => 'application/pdf'],
            ]),
        ]);

        $result = $service->generatePdf('<xml/>', 'D394');

        $this->assertSame($pdfContent, $result);
    }

    public function testGeneratePdfServiceUnavailableThrows(): void
    {
        $service = $this->createService([
            new MockResponse(
                json_encode(['error' => 'DUK PDF generation unavailable']),
                ['http_code' => 503],
            ),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DUK PDF generation service unavailable');
        $service->generatePdf('<xml/>', 'D394');
    }

    public function testGeneratePdf422ThrowsWithMessage(): void
    {
        $service = $this->createService([
            new MockResponse(
                json_encode(['error' => 'DUK PDF validation errors: Missing CIF']),
                ['http_code' => 422],
            ),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DUK PDF validation errors: Missing CIF');
        $service->generatePdf('<xml/>', 'D394');
    }

    public function testIsAvailableReturnsTrueWhenDukReady(): void
    {
        $service = $this->createService([
            new MockResponse(json_encode([
                'status' => 'ok',
                'duk' => true,
            ])),
        ]);

        $this->assertTrue($service->isAvailable());
    }

    public function testIsAvailableReturnsFalseWhenDukNotReady(): void
    {
        $service = $this->createService([
            new MockResponse(json_encode([
                'status' => 'ok',
                'duk' => false,
            ])),
        ]);

        $this->assertFalse($service->isAvailable());
    }

    public function testIsAvailableReturnsFalseOnConnectionError(): void
    {
        $service = $this->createService([
            new MockResponse('', ['error' => 'Connection refused']),
        ]);

        $this->assertFalse($service->isAvailable());
    }

    public function testDefaultServiceUrl(): void
    {
        // When empty javaServiceUrl, should default to 127.0.0.1:8082
        $httpClient = new MockHttpClient([
            new MockResponse(json_encode(['status' => 'ok', 'duk' => true])),
        ]);
        $service = new DukIntegratorService(
            logger: new NullLogger(),
            httpClient: $httpClient,
        );

        $this->assertTrue($service->isAvailable());
    }
}
