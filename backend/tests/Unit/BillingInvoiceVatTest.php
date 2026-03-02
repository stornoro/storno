<?php

namespace App\Tests\Unit;

use App\Entity\Company;
use App\Entity\Organization;
use App\Entity\VatRate;
use App\Manager\InvoiceManager;
use App\Repository\CompanyRepository;
use App\Repository\DocumentSeriesRepository;
use App\Repository\VatRateRepository;
use App\Service\BillingInvoiceService;
use App\Service\EuVatRateService;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests the VAT decision tree in BillingInvoiceService::resolveVatForBuyer().
 *
 * Since resolveVatForBuyer is private, we test it indirectly via reflection.
 */
class BillingInvoiceVatTest extends TestCase
{
    private function createBillingCompany(string $country = 'RO', bool $oss = false): Company
    {
        $company = $this->createMock(Company::class);
        $company->method('getCountry')->willReturn($country);
        $company->method('isOss')->willReturn($oss);

        return $company;
    }

    private function createBuyerCompany(string $country, bool $vatPayer = false): Company
    {
        $company = $this->createMock(Company::class);
        $company->method('getCountry')->willReturn($country);
        $company->method('isVatPayer')->willReturn($vatPayer);
        $company->method('getCif')->willReturn(12345678);

        return $company;
    }

    private function createOrg(Company $buyerCompany): Organization
    {
        $org = $this->createMock(Organization::class);
        $collection = new ArrayCollection([$buyerCompany]);
        $org->method('getCompanies')->willReturn($collection);
        $org->method('getName')->willReturn('Test Org');

        return $org;
    }

    private function createOrgWithoutCompanies(): Organization
    {
        $org = $this->createMock(Organization::class);
        $org->method('getCompanies')->willReturn(new ArrayCollection());
        $org->method('getName')->willReturn('Test Org');

        return $org;
    }

    private function createDefaultVatRate(string $rate = '19.00', string $categoryCode = 'S'): VatRate
    {
        $vatRate = $this->createMock(VatRate::class);
        $vatRate->method('getRate')->willReturn($rate);
        $vatRate->method('getCategoryCode')->willReturn($categoryCode);

        return $vatRate;
    }

    private function callResolveVat(
        Company $billingCompany,
        Organization $org,
        ?VatRate $defaultVatRate = null,
        ?float $ossStandardRate = null,
        bool $billingCompanyOss = false,
    ): array {
        $vatRateRepo = $this->createMock(VatRateRepository::class);
        $vatRateRepo->method('findDefaultByCompany')->willReturn($defaultVatRate);

        $euVatRateService = $this->createMock(EuVatRateService::class);
        if ($ossStandardRate !== null) {
            $euVatRateService->method('getStandardRate')->willReturn($ossStandardRate);
        } else {
            $euVatRateService->method('getStandardRate')->willReturn(null);
        }

        $service = new BillingInvoiceService(
            $this->createMock(InvoiceManager::class),
            $this->createMock(CompanyRepository::class),
            $this->createMock(DocumentSeriesRepository::class),
            $vatRateRepo,
            $euVatRateService,
            $this->createMock(EntityManagerInterface::class),
            new NullLogger(),
            null, // billingCompanyId not needed for this test
        );

        // Call private method via reflection
        $ref = new \ReflectionMethod($service, 'resolveVatForBuyer');

        return $ref->invoke($service, $billingCompany, $org);
    }

    // ─── Domestic (same country) ────────────────────────────

    public function testDomesticRomanianBuyer(): void
    {
        $billing = $this->createBillingCompany('RO');
        $buyer = $this->createBuyerCompany('RO');
        $org = $this->createOrg($buyer);
        $defaultVat = $this->createDefaultVatRate('19.00', 'S');

        [$rate, $code] = $this->callResolveVat($billing, $org, $defaultVat);

        $this->assertSame('19.00', $rate);
        $this->assertSame('S', $code);
    }

    public function testDomesticSameCountryNonRo(): void
    {
        $billing = $this->createBillingCompany('DE');
        $buyer = $this->createBuyerCompany('DE');
        $org = $this->createOrg($buyer);
        $defaultVat = $this->createDefaultVatRate('19.00', 'S');

        [$rate, $code] = $this->callResolveVat($billing, $org, $defaultVat);

        $this->assertSame('19.00', $rate);
        $this->assertSame('S', $code);
    }

    // ─── EU B2B — Reverse charge ────────────────────────────

    public function testEuVatPayerGetsReverseCharge(): void
    {
        $billing = $this->createBillingCompany('RO');
        $buyer = $this->createBuyerCompany('DE', vatPayer: true);
        $org = $this->createOrg($buyer);

        [$rate, $code] = $this->callResolveVat($billing, $org);

        $this->assertSame('0.00', $rate);
        $this->assertSame('AE', $code);
    }

    public function testEuVatPayerHungaryGetsReverseCharge(): void
    {
        $billing = $this->createBillingCompany('RO');
        $buyer = $this->createBuyerCompany('HU', vatPayer: true);
        $org = $this->createOrg($buyer);

        [$rate, $code] = $this->callResolveVat($billing, $org);

        $this->assertSame('0.00', $rate);
        $this->assertSame('AE', $code);
    }

    // ─── EU B2C / non-VAT — OSS ────────────────────────────

    public function testEuNonVatPayerWithOssGetsDestinationRate(): void
    {
        $billing = $this->createBillingCompany('RO', oss: true);
        $buyer = $this->createBuyerCompany('DE', vatPayer: false);
        $org = $this->createOrg($buyer);

        [$rate, $code] = $this->callResolveVat($billing, $org, ossStandardRate: 19.0);

        $this->assertSame('19.00', $rate);
        $this->assertSame('S', $code);
    }

    public function testEuNonVatPayerWithOssHungary27(): void
    {
        $billing = $this->createBillingCompany('RO', oss: true);
        $buyer = $this->createBuyerCompany('HU', vatPayer: false);
        $org = $this->createOrg($buyer);

        [$rate, $code] = $this->callResolveVat($billing, $org, ossStandardRate: 27.0);

        $this->assertSame('27.00', $rate);
        $this->assertSame('S', $code);
    }

    public function testEuNonVatPayerWithOssFrance20(): void
    {
        $billing = $this->createBillingCompany('RO', oss: true);
        $buyer = $this->createBuyerCompany('FR', vatPayer: false);
        $org = $this->createOrg($buyer);

        [$rate, $code] = $this->callResolveVat($billing, $org, ossStandardRate: 20.0);

        $this->assertSame('20.00', $rate);
        $this->assertSame('S', $code);
    }

    // ─── EU B2C / non-VAT — no OSS ─────────────────────────

    public function testEuNonVatPayerWithoutOssGetsDomesticRate(): void
    {
        $billing = $this->createBillingCompany('RO', oss: false);
        $buyer = $this->createBuyerCompany('DE', vatPayer: false);
        $org = $this->createOrg($buyer);
        $defaultVat = $this->createDefaultVatRate('19.00', 'S');

        [$rate, $code] = $this->callResolveVat($billing, $org, $defaultVat);

        $this->assertSame('19.00', $rate);
        $this->assertSame('S', $code);
    }

    // ─── Non-EU — Export exempt ─────────────────────────────

    public function testNonEuBuyerGetsExportExempt(): void
    {
        $billing = $this->createBillingCompany('RO');
        $buyer = $this->createBuyerCompany('US');
        $org = $this->createOrg($buyer);

        [$rate, $code] = $this->callResolveVat($billing, $org);

        $this->assertSame('0.00', $rate);
        $this->assertSame('G', $code);
    }

    public function testNonEuBuyerUkGetsExportExempt(): void
    {
        $billing = $this->createBillingCompany('RO');
        $buyer = $this->createBuyerCompany('GB');
        $org = $this->createOrg($buyer);

        [$rate, $code] = $this->callResolveVat($billing, $org);

        $this->assertSame('0.00', $rate);
        $this->assertSame('G', $code);
    }

    public function testNonEuBuyerSwitzerlandGetsExportExempt(): void
    {
        $billing = $this->createBillingCompany('RO');
        $buyer = $this->createBuyerCompany('CH');
        $org = $this->createOrg($buyer);

        [$rate, $code] = $this->callResolveVat($billing, $org);

        $this->assertSame('0.00', $rate);
        $this->assertSame('G', $code);
    }

    // ─── Edge cases ─────────────────────────────────────────

    public function testOrgWithNoCompaniesGetsDomesticRate(): void
    {
        $billing = $this->createBillingCompany('RO');
        $org = $this->createOrgWithoutCompanies();
        $defaultVat = $this->createDefaultVatRate('19.00', 'S');

        [$rate, $code] = $this->callResolveVat($billing, $org, $defaultVat);

        $this->assertSame('19.00', $rate);
        $this->assertSame('S', $code);
    }

    public function testNoDefaultVatRateFallsBackTo19(): void
    {
        $billing = $this->createBillingCompany('RO');
        $buyer = $this->createBuyerCompany('RO');
        $org = $this->createOrg($buyer);

        [$rate, $code] = $this->callResolveVat($billing, $org, null);

        $this->assertSame('19.00', $rate);
        $this->assertSame('S', $code);
    }

    public function testOssWithUnavailableRatesFallsToDomestic(): void
    {
        $billing = $this->createBillingCompany('RO', oss: true);
        $buyer = $this->createBuyerCompany('DE', vatPayer: false);
        $org = $this->createOrg($buyer);
        $defaultVat = $this->createDefaultVatRate('19.00', 'S');

        // EuVatRateService returns null (API unavailable)
        [$rate, $code] = $this->callResolveVat($billing, $org, $defaultVat, ossStandardRate: null);

        $this->assertSame('19.00', $rate);
        $this->assertSame('S', $code);
    }
}
