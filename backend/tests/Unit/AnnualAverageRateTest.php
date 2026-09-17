<?php

namespace App\Tests\Unit;

use App\Service\ExchangeRateService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Rent in a foreign currency becomes lei at the average annual rate BNR publishes for the
 * income year, so the Declarația unică shows the same gross income ANAF computes.
 */
final class AnnualAverageRateTest extends KernelTestCase
{
    private function service(): ExchangeRateService
    {
        self::bootKernel();

        return self::getContainer()->get(ExchangeRateService::class);
    }

    public function testItReturnsThePublishedRates(): void
    {
        $service = $this->service();

        foreach ([2023 => 4.9464, 2024 => 4.9746, 2025 => 5.0415] as $year => $expected) {
            $result = $service->getAnnualAverageRate('EUR', $year);
            self::assertNotNull($result, 'no rate for ' . $year);
            self::assertSame($expected, $result['rate']);
            self::assertSame('bnr', $result['source']);
        }
    }

    public function testLeiNeedsNoConversionAndTheFutureHasNoRate(): void
    {
        $service = $this->service();

        self::assertSame(['rate' => 1.0, 'source' => 'ron'], $service->getAnnualAverageRate('RON', 2024));
        self::assertNull($service->getAnnualAverageRate('EUR', (int) date('Y') + 1));
    }

    public function testAGrossRentIsConvertedLikeAnafComputesIt(): void
    {
        $rate = $this->service()->getAnnualAverageRate('EUR', 2024)['rate'];

        // 400 EUR a month over 11 months of the income year
        self::assertSame(21888, (int) round(400 * 11 * $rate));
    }
}
