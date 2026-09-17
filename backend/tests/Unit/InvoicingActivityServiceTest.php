<?php

namespace App\Tests\Unit;

use App\Service\InvoicingActivityService;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The lookup answers a question an outside platform asks about a fiscal code,
 * so the interesting cases are the ones where the answer must be "no": an
 * unknown code, a suspended organization, a malformed input.
 */
class InvoicingActivityServiceTest extends TestCase
{
    /**
     * @param list<array<string, mixed>> $companyRows
     * @param list<int>                  $counts      one per expected COUNT query
     */
    private function service(array $companyRows, array $counts = []): InvoicingActivityService
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);

        $queries = [];

        $companyQuery = $this->createMock(AbstractQuery::class);
        $companyQuery->method('setParameter')->willReturnSelf();
        $companyQuery->method('getScalarResult')->willReturn($companyRows);
        $queries[] = $companyQuery;

        foreach ($counts as $count) {
            $countQuery = $this->createMock(AbstractQuery::class);
            $countQuery->method('setParameter')->willReturnSelf();
            $countQuery->method('getSingleScalarResult')->willReturn($count);
            $queries[] = $countQuery;
        }

        $entityManager->method('createQuery')->willReturnOnConsecutiveCalls(...$queries);

        return new InvoicingActivityService($entityManager);
    }

    public function testUnknownFiscalCodeIsNotActive(): void
    {
        $result = $this->service([])->lookup('10000001');

        $this->assertFalse($result['active']);
        $this->assertSame(0, $result['invoicesLast30d']);
    }

    public function testKnownCompanyReportsItsInvoiceCount(): void
    {
        $result = $this->service([['id' => 'abc']], [7])->lookup('10000001');

        $this->assertTrue($result['active']);
        $this->assertSame(7, $result['invoicesLast30d']);
        $this->assertSame(30, $result['windowDays']);
    }

    public function testAccountWithoutRecentInvoicesIsActiveButCountsZero(): void
    {
        // The distinction matters: the discount is for invoicing, not for having
        // signed up and gone quiet.
        $result = $this->service([['id' => 'abc']], [0])->lookup('10000001');

        $this->assertTrue($result['active']);
        $this->assertSame(0, $result['invoicesLast30d']);
    }

    public function testCustomWindowStillReportsTheThirtyDayFigure(): void
    {
        // 90-day window asked for, so two counts: the window and the fixed 30 days.
        $result = $this->service([['id' => 'abc']], [12, 4])->lookup('10000001', 90);

        $this->assertSame(90, $result['windowDays']);
        $this->assertSame(12, $result['invoicesInWindow']);
        $this->assertSame(4, $result['invoicesLast30d']);
    }

    public function testWindowIsClampedToSaneBounds(): void
    {
        $this->assertSame(365, $this->service([], [])->lookup('10000001', 5000)['windowDays']);
        $this->assertSame(1, $this->service([], [])->lookup('10000001', 0)['windowDays']);
        $this->assertSame(1, $this->service([], [])->lookup('10000001', -30)['windowDays']);
    }

    #[DataProvider('messyFiscalCodes')]
    public function testFiscalCodesAreAcceptedHoweverTheyAreWritten(string $written): void
    {
        $this->assertTrue($this->service([['id' => 'abc']], [3])->lookup($written)['active']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function messyFiscalCodes(): iterable
    {
        yield 'plain' => ['10000001'];
        yield 'with prefix' => ['RO10000001'];
        yield 'lowercase prefix' => ['ro10000001'];
        yield 'spaced' => ['RO 10000001'];
        yield 'dotted' => ['10.000.001'];
    }

    #[DataProvider('unusableInput')]
    public function testUnusableInputIsRefusedWithoutTouchingTheDatabase(string $written): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('createQuery');

        $result = (new InvoicingActivityService($entityManager))->lookup($written);

        $this->assertFalse($result['active']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableInput(): iterable
    {
        yield 'empty' => [''];
        yield 'letters only' => ['ABC'];
        yield 'too long' => ['123456789012345'];
    }
}
