<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Exception\AnafDownloadExpiredException;
use App\Service\Anaf\AnafRateLimiter;
use App\Service\Anaf\EFacturaClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** ANAF answers a download after the 60-day window with a 200 and a plain-text refusal instead of the zip. */
final class EFacturaClientExpiredTest extends TestCase
{
    public function testSixtyDayRefusalIsAnExpiredException(): void
    {
        $http = new MockHttpClient(new MockResponse('Fisierul nu mai poate fi descarcat pentru ca a trecut perioada de 60 de zile in care este disponibil', ['http_code' => 200]));
        $client = new EFacturaClient($http, $this->createMock(AnafRateLimiter::class), 'test');

        $this->expectException(AnafDownloadExpiredException::class);
        $this->expectExceptionMessage('60-day window');
        $client->download('7905774901', 'token');
    }

    public function testOtherNonZipAnswersStayGenericErrors(): void
    {
        $http = new MockHttpClient(new MockResponse('{"eroare":"Nu exista niciun fisier cu id-ul dat"}', ['http_code' => 200]));
        $client = new EFacturaClient($http, $this->createMock(AnafRateLimiter::class), 'test');

        try {
            $client->download('1', 'token');
            self::fail('expected an exception');
        } catch (AnafDownloadExpiredException) {
            self::fail('a missing file is not the 60-day expiry');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('non-ZIP', $e->getMessage());
        }
    }
}
