<?php

namespace App\Tests\Unit;

use App\Entity\Client;
use App\Entity\Company;
use App\Entity\Product;
use App\Entity\User;
use App\Manager\DeliveryNoteManager;
use App\Manager\InvoiceManager;
use App\Manager\ProformaInvoiceManager;
use App\Manager\ReceiptManager;
use App\Manager\RecurringInvoiceManager;
use App\Repository\ClientRepository;
use App\Repository\DeliveryNoteRepository;
use App\Repository\DocumentSeriesRepository;
use App\Repository\InvoiceRepository;
use App\Repository\ProductRepository;
use App\Repository\ProformaInvoiceRepository;
use App\Repository\ReceiptRepository;
use App\Repository\RecurringInvoiceRepository;
use App\Repository\StripeConnectAccountRepository;
use App\Service\Anaf\AnafTokenResolver;
use App\Service\EuVatRateService;
use App\Service\ExchangeRateService;
use App\Service\LicenseManager;
use App\Validator\UblExtensionsValidator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Request-supplied clientId / productId must resolve to entities owned by the
 * company the document is created for. Ids from another tenant are rejected
 * with the same "not found" DomainException the managers already use, so the
 * controllers turn them into a 4xx instead of silently attaching foreign data.
 */
class ManagerCrossTenantGuardTest extends TestCase
{
    private Company $company;
    private Company $otherCompany;
    private User $user;

    protected function setUp(): void
    {
        $this->company = $this->makeCompany('Own SRL', 111);
        $this->otherCompany = $this->makeCompany('Other SRL', 222);
        $this->user = new User();
    }

    private function makeCompany(string $name, int $cif): Company
    {
        $company = new Company();
        $company->setName($name);
        $company->setCif($cif);

        return $company;
    }

    private function makeClient(Company $owner): Client
    {
        $client = new Client();
        $client->setName('Client ' . $owner->getName());
        $client->setCompany($owner);

        return $client;
    }

    private function makeProduct(Company $owner): Product
    {
        $product = new Product();
        $product->setName('Product ' . $owner->getName());
        $product->setCompany($owner);

        return $product;
    }

    private function clientRepositoryReturning(?Client $client): ClientRepository
    {
        $repo = $this->createMock(ClientRepository::class);
        $repo->method('find')->willReturn($client);

        return $repo;
    }

    private function productRepositoryReturning(?Product $product): ProductRepository
    {
        $repo = $this->createMock(ProductRepository::class);
        $repo->method('find')->willReturn($product);

        return $repo;
    }

    private function makeInvoiceManager(ClientRepository $clientRepo, ProductRepository $productRepo): InvoiceManager
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnArgument(0);

        return new InvoiceManager(
            $this->createMock(InvoiceRepository::class),
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(DocumentSeriesRepository::class),
            $clientRepo,
            $productRepo,
            $this->createMock(StripeConnectAccountRepository::class),
            $this->createMock(MessageBusInterface::class),
            $this->createMock(AnafTokenResolver::class),
            $this->createMock(EuVatRateService::class),
            $this->createMock(UblExtensionsValidator::class),
            $dispatcher,
            $this->createMock(LicenseManager::class),
        );
    }

    // ---- InvoiceManager -------------------------------------------------

    public function testInvoiceCreateRejectsClientFromAnotherCompany(): void
    {
        $manager = $this->makeInvoiceManager(
            $this->clientRepositoryReturning($this->makeClient($this->otherCompany)),
            $this->createMock(ProductRepository::class),
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Client not found.');

        $manager->create($this->company, ['clientId' => (string) Uuid::v7(), 'lines' => []], $this->user);
    }

    public function testInvoiceCreateRejectsUnknownClientId(): void
    {
        $manager = $this->makeInvoiceManager(
            $this->clientRepositoryReturning(null),
            $this->createMock(ProductRepository::class),
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Client not found.');

        $manager->create($this->company, ['clientId' => (string) Uuid::v7(), 'lines' => []], $this->user);
    }

    public function testInvoiceCreateAttachesClientFromSameCompany(): void
    {
        $client = $this->makeClient($this->company);
        $manager = $this->makeInvoiceManager(
            $this->clientRepositoryReturning($client),
            $this->createMock(ProductRepository::class),
        );

        $invoice = $manager->create($this->company, ['clientId' => (string) $client->getId(), 'lines' => []], $this->user);

        $this->assertSame($client, $invoice->getClient());
        $this->assertSame($client->getName(), $invoice->getReceiverName());
    }

    public function testInvoiceUpdateRejectsClientFromAnotherCompany(): void
    {
        $client = $this->makeClient($this->company);
        $manager = $this->makeInvoiceManager(
            $this->clientRepositoryReturning($client),
            $this->createMock(ProductRepository::class),
        );
        $invoice = $manager->create($this->company, ['clientId' => (string) $client->getId(), 'lines' => []], $this->user);

        $foreignManager = $this->makeInvoiceManager(
            $this->clientRepositoryReturning($this->makeClient($this->otherCompany)),
            $this->createMock(ProductRepository::class),
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Client not found.');

        $foreignManager->update($invoice, ['clientId' => (string) Uuid::v7()], $this->user);
    }

    public function testInvoiceCreateRejectsProductFromAnotherCompany(): void
    {
        $manager = $this->makeInvoiceManager(
            $this->createMock(ClientRepository::class),
            $this->productRepositoryReturning($this->makeProduct($this->otherCompany)),
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Product not found.');

        $manager->create($this->company, [
            'lines' => [
                ['productId' => (string) Uuid::v7(), 'description' => 'X', 'quantity' => '1', 'unitPrice' => '10.00'],
            ],
        ], $this->user);
    }

    public function testInvoiceCreateLinksProductFromSameCompany(): void
    {
        $product = $this->makeProduct($this->company);
        $manager = $this->makeInvoiceManager(
            $this->createMock(ClientRepository::class),
            $this->productRepositoryReturning($product),
        );

        $invoice = $manager->create($this->company, [
            'lines' => [
                ['productId' => (string) $product->getId(), 'description' => 'X', 'quantity' => '1', 'unitPrice' => '10.00'],
            ],
        ], $this->user);

        $this->assertSame($product, $invoice->getLines()->first()->getProduct());
    }

    // ---- ProformaInvoiceManager -----------------------------------------

    public function testProformaCreateRejectsClientFromAnotherCompany(): void
    {
        $manager = new ProformaInvoiceManager(
            $this->createMock(ProformaInvoiceRepository::class),
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(DocumentSeriesRepository::class),
            $this->clientRepositoryReturning($this->makeClient($this->otherCompany)),
            $this->createMock(ProductRepository::class),
            $this->createMock(InvoiceManager::class),
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Client not found.');

        $manager->create($this->company, ['clientId' => (string) Uuid::v7()], $this->user);
    }

    public function testProformaCreateRejectsProductFromAnotherCompany(): void
    {
        $manager = new ProformaInvoiceManager(
            $this->createMock(ProformaInvoiceRepository::class),
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(DocumentSeriesRepository::class),
            $this->createMock(ClientRepository::class),
            $this->productRepositoryReturning($this->makeProduct($this->otherCompany)),
            $this->createMock(InvoiceManager::class),
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Product not found.');

        $manager->create($this->company, [
            'lines' => [['productId' => (string) Uuid::v7(), 'description' => 'X']],
        ], $this->user);
    }

    // ---- ReceiptManager ---------------------------------------------------

    public function testReceiptCreateRejectsClientFromAnotherCompany(): void
    {
        $manager = new ReceiptManager(
            $this->createMock(ReceiptRepository::class),
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(DocumentSeriesRepository::class),
            $this->clientRepositoryReturning($this->makeClient($this->otherCompany)),
            $this->createMock(InvoiceManager::class),
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Client not found.');

        $manager->create($this->company, ['clientId' => (string) Uuid::v7()], $this->user);
    }

    // ---- DeliveryNoteManager ---------------------------------------------

    public function testDeliveryNoteCreateRejectsClientFromAnotherCompany(): void
    {
        $manager = new DeliveryNoteManager(
            $this->createMock(DeliveryNoteRepository::class),
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(DocumentSeriesRepository::class),
            $this->clientRepositoryReturning($this->makeClient($this->otherCompany)),
            $this->createMock(InvoiceManager::class),
            $this->createMock(ProformaInvoiceRepository::class),
            $this->createMock(MessageBusInterface::class),
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Client not found.');

        $manager->create($this->company, ['clientId' => (string) Uuid::v7()], $this->user);
    }

    // ---- RecurringInvoiceManager -----------------------------------------

    private function makeRecurringManager(ClientRepository $clientRepo, ProductRepository $productRepo): RecurringInvoiceManager
    {
        return new RecurringInvoiceManager(
            $this->createMock(RecurringInvoiceRepository::class),
            $this->createMock(EntityManagerInterface::class),
            $clientRepo,
            $this->createMock(DocumentSeriesRepository::class),
            $productRepo,
            $this->createMock(ExchangeRateService::class),
        );
    }

    public function testRecurringCreateRejectsClientFromAnotherCompany(): void
    {
        $manager = $this->makeRecurringManager(
            $this->clientRepositoryReturning($this->makeClient($this->otherCompany)),
            $this->createMock(ProductRepository::class),
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Client not found.');

        $manager->create($this->company, ['clientId' => (string) Uuid::v7()]);
    }

    public function testRecurringCreateRejectsProductFromAnotherCompany(): void
    {
        $manager = $this->makeRecurringManager(
            $this->createMock(ClientRepository::class),
            $this->productRepositoryReturning($this->makeProduct($this->otherCompany)),
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Product not found.');

        $manager->create($this->company, [
            'lines' => [['productId' => (string) Uuid::v7(), 'description' => 'X']],
        ]);
    }

    public function testRecurringCreateAcceptsOwnClientAndProduct(): void
    {
        $client = $this->makeClient($this->company);
        $product = $this->makeProduct($this->company);
        $manager = $this->makeRecurringManager(
            $this->clientRepositoryReturning($client),
            $this->productRepositoryReturning($product),
        );

        $ri = $manager->create($this->company, [
            'clientId' => (string) $client->getId(),
            'lines' => [['productId' => (string) $product->getId(), 'description' => 'X']],
        ]);

        $this->assertSame($client, $ri->getClient());
        $this->assertSame($product, $ri->getLines()->first()->getProduct());
    }
}
