<?php

namespace App\Tests\Unit;

use App\Service\Declaration\AnafWebFormPdfService;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * ANAF renders the filing PDF of its web-application forms from the same XML Storno builds.
 * The answer is a React stream, so the file has to be cut out of it.
 */
final class AnafWebFormPdfTest extends TestCase
{
    private function service(MockResponse $response): AnafWebFormPdfService
    {
        return new AnafWebFormPdfService(new MockHttpClient($response), new NullLogger());
    }

    private function stream(string $pdf): string
    {
        $base64 = base64_encode($pdf);

        return '0:{"a":"$@1","f":"","q":"","i":false,"b":"test"}' . "\n"
            . '2:T' . dechex(strlen($base64)) . ',' . $base64
            . '1:{"ok":true,"status":200,"contentType":"application/pdf","base64":"$2"}' . "\n";
    }

    public function testItOnlyHandlesTheCampaignsFiledOnTheWeb(): void
    {
        $service = $this->service(new MockResponse(''));

        self::assertTrue($service->handles('d212', 2026));
        self::assertTrue($service->handles('D212', 2027));
        self::assertFalse($service->handles('D212', 2025), 'earlier campaigns keep the local validator');
        self::assertFalse($service->handles('D300', 2026));
        self::assertFalse($service->handles('D212', null));
    }

    public function testItReturnsThePdfCarriedByTheStream(): void
    {
        $pdf = "%PDF-1.6\n1 0 obj<</Type/Catalog>>endobj\n%%EOF";

        $result = $this->service(new MockResponse($this->stream($pdf)))->render('D212', '<d212/>');

        self::assertSame($pdf, $result);
    }

    public function testAnAnswerWithoutAPdfIsAnError(): void
    {
        $service = $this->service(new MockResponse('1:{"ok":false,"status":500,"error":"XML invalid"}' . "\n"));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/XML invalid/');
        $service->render('D212', '<d212/>');
    }

    public function testAnHttpErrorIsReported(): void
    {
        $service = $this->service(new MockResponse('nope', ['http_code' => 503]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/503/');
        $service->render('D212', '<d212/>');
    }

    public function testAnUnknownFormIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->service(new MockResponse(''))->render('D300', '<x/>');
    }
}
