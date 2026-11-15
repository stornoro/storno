<?php

namespace App\Tests\Unit;

use App\Entity\Company;
use App\Enum\DeclarationType;
use App\Manager\TaxDeclarationManager;
use PHPUnit\Framework\TestCase;

/**
 * A quarterly profit-tax payer files its D100 for 03, 06, 09, 12; any other month is refused
 * unless the caller says the return holds monthly obligations only (periodType "monthly").
 */
class TaxDeclarationManagerD100PeriodTest extends TestCase
{
    private function check(Company $company, DeclarationType $type, int $month, ?string $periodType): void
    {
        $manager = (new \ReflectionClass(TaxDeclarationManager::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(TaxDeclarationManager::class, 'assertD100Period');
        $method->setAccessible(true);
        $method->invoke($manager, $company, $type, $month, $periodType);
    }

    public function testQuarterEndMonthsPass(): void
    {
        $company = (new Company())->setIncomeTaxPeriod(Company::PERIOD_QUARTERLY);
        foreach ([3, 6, 9, 12] as $month) {
            $this->check($company, DeclarationType::D100, $month, null);
        }
        $this->addToAssertionCount(4);
    }

    public function testOtherMonthIsRefusedForAQuarterlyPayer(): void
    {
        $company = (new Company())->setIncomeTaxPeriod(Company::PERIOD_QUARTERLY);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/luna 07 nu incheie un trimestru/');
        $this->check($company, DeclarationType::D100, 7, null);
    }

    public function testMonthlyObligationsAndMonthlyPayersAreAccepted(): void
    {
        $quarterly = (new Company())->setIncomeTaxPeriod(Company::PERIOD_QUARTERLY);
        $this->check($quarterly, DeclarationType::D100, 7, 'monthly');
        $this->check($quarterly, DeclarationType::D112, 7, null);
        $monthly = (new Company())->setIncomeTaxPeriod(Company::PERIOD_MONTHLY);
        $this->check($monthly, DeclarationType::D100, 7, null);
        $this->addToAssertionCount(3);
    }
}
