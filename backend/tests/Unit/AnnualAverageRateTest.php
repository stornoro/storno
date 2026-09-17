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

    /**
     * The figures BNR published for those years; computing them from its own yearly files
     * must land on the same number, which is what makes the conversion defensible.
     *
     * @group network
     */
    public function testItComputesThePublishedAnnualAverages(): void
    {
        $service = $this->service();

        foreach ([2024 => 4.9746, 2025 => 5.0415] as $year => $published) {
            $result = $service->getAnnualAverageRate('EUR', $year);
            if ($result === null) {
                self::markTestSkipped('BNR is unreachable from this machine');
            }
            self::assertSame('bnr', $result['source']);
            self::assertSame(12, $result['months']);
            self::assertSame($published, $result['rate'], 'annual average of ' . $year);
        }
    }

    public function testLeiNeedsNoConversionAndTheFutureHasNoRate(): void
    {
        $service = $this->service();

        self::assertSame(['rate' => 1.0, 'source' => 'ron'], $service->getAnnualAverageRate('RON', 2024));
        self::assertNull($service->getAnnualAverageRate('EUR', (int) date('Y') + 1));
    }

    /** @group network */
    public function testAGrossRentIsConvertedLikeAnafComputesIt(): void
    {
        $result = $this->service()->getAnnualAverageRate('EUR', 2024);
        if ($result === null) {
            self::markTestSkipped('BNR is unreachable from this machine');
        }

        // 400 EUR a month over 11 months of the income year
        self::assertSame(21888, (int) round(400 * 11 * $result['rate']));
    }
}
