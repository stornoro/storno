<?php

namespace App\Tests\Unit;

use App\Service\EuVatRateService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class EuVatRateServiceTest extends TestCase
{
    private function createService(string $jsonBody, int $statusCode = 200): EuVatRateService
    {
        $mockResponse = new MockResponse($jsonBody, ['http_code' => $statusCode]);
        $httpClient = new MockHttpClient($mockResponse);
        $cache = new ArrayAdapter();

        return new EuVatRateService($httpClient, $cache);
    }

    private function sampleRatesJson(): string
    {
        return json_encode([
            'items' => [
                'HU' => [
                    'name' => 'Hungary',
                    'code' => 'HU',
                    'country_code' => 'HU',
                    'periods' => [
                        [
                            'effective_from' => '2024-01-01',
                            'rates' => [
                                'standard' => 27,
                                'reduced' => 18,
                                'super_reduced' => 5,
                            ],
                        ],
                        [
                            'effective_from' => '2012-01-01',
                            'rates' => [
                                'standard' => 27,
                                'reduced' => 18,
                                'super_reduced' => 5,
                            ],
                        ],
                    ],
                ],
                'DE' => [
                    'name' => 'Germany',
                    'code' => 'DE',
                    'country_code' => 'DE',
                    'periods' => [
                        [
                            'effective_from' => '2021-01-01',
                            'rates' => [
                                'standard' => 19,
                                'reduced' => 7,
                            ],
                        ],
                        [
                            'effective_from' => '2020-07-01',
                            'rates' => [
                                'standard' => 16,
                                'reduced' => 5,
                            ],
                        ],
                    ],
                ],
                'FR' => [
                    'name' => 'France',
                    'code' => 'FR',
                    'country_code' => 'FR',
                    'periods' => [
                        [
                            'effective_from' => '2014-01-01',
                            'rates' => [
                                'standard' => 20,
                                'reduced' => 10,
                                'super_reduced' => 5.5,
                            ],
                        ],
                    ],
                ],
                'IE' => [
                    'name' => 'Ireland',
                    'code' => 'IE',
                    'country_code' => 'IE',
                    'periods' => [
                        [
                            'effective_from' => '2024-01-01',
                            'rates' => [
                                'standard' => 23,
                                'reduced' => 13.5,
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function testGetStandardRateHungary(): void
    {
        $service = $this->createService($this->sampleRatesJson());

        $rate = $service->getStandardRate('HU');
        $this->assertSame(27.0, $rate);
    }

    public function testGetStandardRateGermany(): void
    {
        $service = $this->createService($this->sampleRatesJson());

        $rate = $service->getStandardRate('DE');
        $this->assertSame(19.0, $rate);
    }

    public function testGetStandardRateFrance(): void
    {
        $service = $this->createService($this->sampleRatesJson());

        $rate = $service->getStandardRate('FR');
        $this->assertSame(20.0, $rate);
    }

    public function testGetStandardRateIreland(): void
    {
        $service = $this->createService($this->sampleRatesJson());

        $rate = $service->getStandardRate('IE');
        $this->assertSame(23.0, $rate);
    }

    public function testGetStandardRateCaseInsensitive(): void
    {
        $service = $this->createService($this->sampleRatesJson());

        $rate = $service->getStandardRate('hu');
        $this->assertSame(27.0, $rate);
    }

    public function testGetStandardRateUnknownCountry(): void
    {
        $service = $this->createService($this->sampleRatesJson());

        $rate = $service->getStandardRate('XX');
        $this->assertNull($rate);
    }

    public function testGetStandardRateEmptyCountry(): void
    {
        $service = $this->createService($this->sampleRatesJson());

        $rate = $service->getStandardRate('');
        $this->assertNull($rate);
    }

    public function testGetStandardRateHttpError(): void
    {
        $service = $this->createService('', 500);

        $rate = $service->getStandardRate('HU');
        $this->assertNull($rate);
    }

    public function testGetStandardRateInvalidJson(): void
    {
        $service = $this->createService('not valid json');

        $rate = $service->getStandardRate('HU');
        $this->assertNull($rate);
    }

    public function testGetStandardRateEmptyItems(): void
    {
        $service = $this->createService(json_encode(['items' => []]));

        $rate = $service->getStandardRate('HU');
        $this->assertNull($rate);
    }

    public function testGetStandardRateCountryWithNoPeriods(): void
    {
        $json = json_encode([
            'items' => [
                'HU' => [
                    'name' => 'Hungary',
                    'periods' => [],
                ],
            ],
        ]);
        $service = $this->createService($json);

        $rate = $service->getStandardRate('HU');
        $this->assertNull($rate);
    }

    public function testGetStandardRateFuturePeriodSkipped(): void
    {
        $json = json_encode([
            'items' => [
                'HU' => [
                    'name' => 'Hungary',
                    'periods' => [
                        [
                            'effective_from' => '2099-01-01',
                            'rates' => ['standard' => 30],
                        ],
                        [
                            'effective_from' => '2020-01-01',
                            'rates' => ['standard' => 27],
                        ],
                    ],
                ],
            ],
        ]);
        $service = $this->createService($json);

        $rate = $service->getStandardRate('HU');
        $this->assertSame(27.0, $rate);
    }

    public function testResultsAreCached(): void
    {
        // First call fetches from HTTP
        $responses = [
            new MockResponse($this->sampleRatesJson()),
            // Second call should NOT trigger another HTTP request — it uses cache
            // If it does, MockHttpClient will throw because there are no more responses
        ];
        $httpClient = new MockHttpClient($responses);
        $cache = new ArrayAdapter();
        $service = new EuVatRateService($httpClient, $cache);

        $rate1 = $service->getStandardRate('HU');
        $rate2 = $service->getStandardRate('DE');

        $this->assertSame(27.0, $rate1);
        $this->assertSame(19.0, $rate2);
        // Only one HTTP request was made (the second call used cache)
        $this->assertSame(1, $httpClient->getRequestsCount());
    }
}
