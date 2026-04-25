<?php

namespace App\Tests\Unit;

use App\Controller\Api\V1\DashboardController;
use App\Entity\Company;
use App\Repository\ClientRepository;
use App\Repository\CompanyRepository;
use App\Repository\InvoiceRepository;
use App\Repository\ProductRepository;
use App\Security\OrganizationContext;
use App\Security\Permission;
use App\Service\ExchangeRateService;
use App\Service\PaymentService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/**
 * Verifies the shape of the `previousPeriod` block returned by
 * GET /api/v1/dashboard/stats when a date filter is active.
 *
 * No real database is required — all DBAL calls are intercepted by a mock
 * Connection whose return values are scripted with willReturnOnConsecutiveCalls.
 */
class DashboardPreviousPeriodTest extends TestCase
{
    private DashboardController $controller;

    private OrganizationContext&MockObject $orgCtx;
    private EntityManagerInterface&MockObject $em;
    private Connection&MockObject $conn;
    private ExchangeRateService&MockObject $exchangeRateService;
    private PaymentService&MockObject $paymentService;
    private Company&MockObject $company;

    protected function setUp(): void
    {
        $this->orgCtx              = $this->createMock(OrganizationContext::class);
        $this->em                  = $this->createMock(EntityManagerInterface::class);
        $this->conn                = $this->createMock(Connection::class);
        $this->exchangeRateService = $this->createMock(ExchangeRateService::class);
        $this->paymentService      = $this->createMock(PaymentService::class);
        $this->company             = $this->createMock(Company::class);

        $this->em->method('getConnection')->willReturn($this->conn);

        $companyId = Uuid::v4();
        $this->company->method('getId')->willReturn($companyId);
        $this->company->method('getDefaultCurrency')->willReturn('RON');
        $this->company->method('getLastSyncedAt')->willReturn(null);
        $this->company->method('isSyncEnabled')->willReturn(false);

        $this->orgCtx->method('resolveCompany')->willReturnCallback(fn () => $this->company);
        $this->orgCtx->method('hasPermission')->with(Permission::REPORT_VIEW)->willReturn(true);

        $this->exchangeRateService->method('getRate')->willReturn(1.0);
        $this->exchangeRateService->method('buildFallbackRateSql')->willReturn('1');

        $this->paymentService->method('getPaymentSummary')->willReturn([]);

        $this->controller = new DashboardController(
            $this->createMock(InvoiceRepository::class),
            $this->createMock(ClientRepository::class),
            $this->createMock(ProductRepository::class),
            $this->orgCtx,
            $this->createMock(CompanyRepository::class),
            $this->em,
            $this->paymentService,
            $this->exchangeRateService,
        );

        // Inject a minimal container so AbstractController::json() works
        $serializer = null; // json() falls back to PHP json_encode without serializer
        $container  = $this->createMock(\Psr\Container\ContainerInterface::class);
        $container->method('has')->willReturnCallback(fn (string $id) => false);
        $container->method('get')->willThrowException(new \RuntimeException("Not in container: $id"));
        $this->controller->setContainer($container);
    }

    /**
     * When dateFrom + dateTo are supplied, the response must include a
     * `previousPeriod` key with the required sub-keys.
     */
    public function testPreviousPeriodBlockIsPresentWhenDateFilterActive(): void
    {
        // ------------------------------------------------------------------ //
        // Script the DBAL mock.
        //
        // stats() fires these queries in order:
        //  1  fetchAllAssociative – direction counts (current period)
        //  2  fetchAllAssociative – status counts
        //  3  fetchAssociative    – totals (current period)
        //  4  fetchOne            – clientCount (current period, hasDateFilter)
        //  5  fetchOne            – productCount (current period, hasDateFilter)
        //  6  fetchAllAssociative – monthlyTotals
        //  7  fetchAllAssociative – amountsByDirection (current period)
        //  8  fetchAllAssociative – direction counts (previous period)
        //  9  fetchAssociative    – totals (previous period)
        // 10  fetchAllAssociative – amountsByDirection (previous period)
        // 11  fetchOne            – clientCount (previous period)
        // 12  fetchOne            – productCount (previous period)
        //
        // invoiceRepository::createQueryBuilder is called for recentActivity;
        // we mock the InvoiceRepository inline so it returns an empty result.
        // ------------------------------------------------------------------ //

        $invoiceRepo = $this->createMock(InvoiceRepository::class);
        $qb          = $this->createMock(\Doctrine\ORM\QueryBuilder::class);
        $query       = $this->createMock(\Doctrine\ORM\AbstractQuery::class);
        $query->method('getResult')->willReturn([]);
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);
        $invoiceRepo->method('createQueryBuilder')->willReturn($qb);

        $controller = new DashboardController(
            $invoiceRepo,
            $this->createMock(ClientRepository::class),
            $this->createMock(ProductRepository::class),
            $this->orgCtx,
            $this->createMock(CompanyRepository::class),
            $this->em,
            $this->paymentService,
            $this->exchangeRateService,
        );
        $controller->setContainer($this->createStubContainer());

        $this->conn->method('fetchAllAssociative')->willReturnOnConsecutiveCalls(
            // 1 direction counts – current
            [['direction' => 'outgoing', 'cnt' => '10'], ['direction' => 'incoming', 'cnt' => '5']],
            // 2 status counts
            [['status' => 'paid', 'cnt' => '10']],
            // 6 monthly totals
            [],
            // 7 amountsByDirection – current
            [['direction' => 'outgoing', 'amount' => '5000.00'], ['direction' => 'incoming', 'amount' => '2000.00']],
            // 8 direction counts – previous
            [['direction' => 'outgoing', 'cnt' => '8'], ['direction' => 'incoming', 'cnt' => '3']],
            // 10 amountsByDirection – previous
            [['direction' => 'outgoing', 'amount' => '4000.00'], ['direction' => 'incoming', 'amount' => '1500.00']]
        );

        $this->conn->method('fetchAssociative')->willReturnOnConsecutiveCalls(
            // 3 totals – current
            ['total_amount' => '7000.00', 'total_vat' => '1330.00'],
            // 9 totals – previous
            ['total_amount' => '5500.00', 'total_vat' => '1045.00']
        );

        $this->conn->method('fetchOne')->willReturnOnConsecutiveCalls(
            // 4 clientCount – current
            7,
            // 5 productCount – current
            12,
            // 11 clientCount – previous
            5,
            // 12 productCount – previous
            9
        );

        $request = new Request(query: ['dateFrom' => '2026-04-01', 'dateTo' => '2026-04-30']);
        $response = $controller->stats($request);
        $data     = json_decode($response->getContent(), true);

        // Top-level key must exist
        $this->assertArrayHasKey('previousPeriod', $data, 'previousPeriod key must be present in the response');

        $pp = $data['previousPeriod'];
        $this->assertIsArray($pp, 'previousPeriod must be an array');

        // Date bounds
        $this->assertArrayHasKey('from', $pp);
        $this->assertArrayHasKey('to', $pp);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $pp['from']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $pp['to']);

        // The previous period must end the day before dateFrom
        $this->assertSame('2026-03-31', $pp['to']);
        // Span of April = 29 days (diff between Apr-01 and Apr-30); prevFrom = Mar-31 - 29 days = Mar-02
        $this->assertSame('2026-03-02', $pp['from']);

        // Invoice counts
        $this->assertArrayHasKey('invoices', $pp);
        $this->assertArrayHasKey('total',    $pp['invoices']);
        $this->assertArrayHasKey('incoming', $pp['invoices']);
        $this->assertArrayHasKey('outgoing', $pp['invoices']);
        $this->assertSame(11, $pp['invoices']['total']); // 8 + 3

        // Amounts
        $this->assertArrayHasKey('amounts', $pp);
        $this->assertArrayHasKey('total', $pp['amounts']);
        $this->assertArrayHasKey('vat',   $pp['amounts']);

        // Amounts by direction
        $this->assertArrayHasKey('amountsByDirection', $pp);
        $this->assertArrayHasKey('incoming', $pp['amountsByDirection']);
        $this->assertArrayHasKey('outgoing', $pp['amountsByDirection']);

        // Counts
        $this->assertArrayHasKey('clientCount',  $pp);
        $this->assertArrayHasKey('productCount', $pp);
        $this->assertSame(5,  $pp['clientCount']);
        $this->assertSame(9,  $pp['productCount']);
    }

    /**
     * When no date filter is given, previousPeriod must be null.
     */
    public function testPreviousPeriodIsNullWithoutDateFilter(): void
    {
        $invoiceRepo = $this->createMock(InvoiceRepository::class);
        $qb          = $this->createMock(\Doctrine\ORM\QueryBuilder::class);
        $query       = $this->createMock(\Doctrine\ORM\AbstractQuery::class);
        $query->method('getResult')->willReturn([]);
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);
        $invoiceRepo->method('createQueryBuilder')->willReturn($qb);

        $controller = new DashboardController(
            $invoiceRepo,
            $this->createMock(ClientRepository::class),
            $this->createMock(ProductRepository::class),
            $this->orgCtx,
            $this->createMock(CompanyRepository::class),
            $this->em,
            $this->paymentService,
            $this->exchangeRateService,
        );
        $controller->setContainer($this->createStubContainer());

        $this->conn->method('fetchAllAssociative')->willReturn([]);
        $this->conn->method('fetchAssociative')->willReturn(['total_amount' => '0.00', 'total_vat' => '0.00']);
        $this->conn->method('fetchOne')->willReturn(0);

        $request  = new Request(); // no date params
        $response = $controller->stats($request);
        $data     = json_decode($response->getContent(), true);

        $this->assertArrayHasKey('previousPeriod', $data);
        $this->assertNull($data['previousPeriod'], 'previousPeriod must be null when no date filter is active');
    }

    // ---------------------------------------------------------------------- //
    // Helpers
    // ---------------------------------------------------------------------- //

    private function createStubContainer(): \Psr\Container\ContainerInterface
    {
        $container = $this->createMock(\Psr\Container\ContainerInterface::class);
        $container->method('has')->willReturn(false);
        $container->method('get')->willThrowException(new \RuntimeException('not in container'));
        return $container;
    }
}
