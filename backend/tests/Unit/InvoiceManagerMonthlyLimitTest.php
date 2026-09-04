<?php

namespace App\Tests\Unit;

use App\Entity\Company;
use App\Entity\Invoice;
use App\Entity\Organization;
use App\Entity\User;
use App\Manager\InvoiceManager;
use App\Repository\ClientRepository;
use App\Repository\DocumentSeriesRepository;
use App\Repository\InvoiceRepository;
use App\Repository\ProductRepository;
use App\Repository\StripeConnectAccountRepository;
use App\Service\Anaf\AnafTokenResolver;
use App\Service\EuVatRateService;
use App\Service\LicenseManager;
use App\Validator\UblExtensionsValidator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Test double: replaces the DQL count with a fixed number so the quota logic
 * can be exercised without a database.
 */
class FixedCountInvoiceManager extends InvoiceManager
{
    public int $outgoingThisMonth = 0;

    protected function countOutgoingInvoicesThisMonth(Organization $organization): int
    {
        return $this->outgoingThisMonth;
    }
}

/**
 * The plan's monthly invoice quota is enforced inside InvoiceManager so that
 * every creation path (conversions, recurring issuance, storno, Stripe app, ...)
 * is covered — not only InvoiceController::create.
 */
class InvoiceManagerMonthlyLimitTest extends TestCase
{
    private Company $company;
    private User $user;

    protected function setUp(): void
    {
        $org = new Organization();
        $org->setName('Org');

        $this->company = new Company();
        $this->company->setName('Own SRL');
        $this->company->setCif(111);
        $this->company->setOrganization($org);

        $this->user = new User();
    }

    private function makeManager(int $maxPerMonth, int $outgoingThisMonth): FixedCountInvoiceManager
    {
        $license = $this->createMock(LicenseManager::class);
        $license->method('getFeatures')->willReturn(['maxInvoicesPerMonth' => $maxPerMonth]);

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnArgument(0);

        $manager = new FixedCountInvoiceManager(
            $this->createMock(InvoiceRepository::class),
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(DocumentSeriesRepository::class),
            $this->createMock(ClientRepository::class),
            $this->createMock(ProductRepository::class),
            $this->createMock(StripeConnectAccountRepository::class),
            $this->createMock(MessageBusInterface::class),
            $this->createMock(AnafTokenResolver::class),
            $this->createMock(EuVatRateService::class),
            $this->createMock(UblExtensionsValidator::class),
            $dispatcher,
            $license,
        );
        $manager->outgoingThisMonth = $outgoingThisMonth;

        return $manager;
    }

    public function testCreateThrowsWhenOrganizationReachedMonthlyLimit(): void
    {
        $manager = $this->makeManager(100, 100);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Monthly invoice limit reached for your plan.');

        $manager->create($this->company, ['lines' => []], $this->user);
    }

    public function testCreateSucceedsBelowMonthlyLimit(): void
    {
        $manager = $this->makeManager(100, 99);

        $invoice = $manager->create($this->company, ['lines' => []], $this->user);

        $this->assertInstanceOf(Invoice::class, $invoice);
    }

    public function testZeroLimitMeansUnlimited(): void
    {
        $manager = $this->makeManager(0, 100000);

        $invoice = $manager->create($this->company, ['lines' => []], $this->user);

        $this->assertInstanceOf(Invoice::class, $invoice);
    }

    public function testCreateStornoIsAlsoLimited(): void
    {
        $manager = $this->makeManager(100, 100);

        $original = new Invoice();
        $original->setCompany($this->company);
        $original->setCurrency('RON');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Monthly invoice limit reached for your plan.');

        $manager->createStorno($original, $this->user);
    }

    public function testIdempotentReplayIsNotBlockedByLimit(): void
    {
        $existing = new Invoice();
        $invoiceRepo = $this->createMock(InvoiceRepository::class);
        $invoiceRepo->method('findOneBy')->willReturn($existing);

        $license = $this->createMock(LicenseManager::class);
        $license->method('getFeatures')->willReturn(['maxInvoicesPerMonth' => 1]);

        $manager = new FixedCountInvoiceManager(
            $invoiceRepo,
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(DocumentSeriesRepository::class),
            $this->createMock(ClientRepository::class),
            $this->createMock(ProductRepository::class),
            $this->createMock(StripeConnectAccountRepository::class),
            $this->createMock(MessageBusInterface::class),
            $this->createMock(AnafTokenResolver::class),
            $this->createMock(EuVatRateService::class),
            $this->createMock(UblExtensionsValidator::class),
            $this->createMock(EventDispatcherInterface::class),
            $license,
        );
        $manager->outgoingThisMonth = 1;

        $result = $manager->create($this->company, ['idempotencyKey' => 'abc', 'lines' => []], $this->user);

        $this->assertSame($existing, $result);
    }
}
