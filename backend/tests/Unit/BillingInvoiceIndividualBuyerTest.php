<?php

namespace App\Tests\Unit;

use App\Entity\Client;
use App\Entity\Company;
use App\Entity\Organization;
use App\Manager\InvoiceManager;
use App\Repository\ClientRepository;
use App\Repository\CompanyRepository;
use App\Repository\DocumentSeriesRepository;
use App\Repository\VatRateRepository;
use App\Service\BillingInvoiceService;
use App\Service\EuVatRateService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A subscriber organization without a company is invoiced as a person:
 * a Client with no CUI and no CNP, so the XML carries the 13-zero identifier.
 */
class BillingInvoiceIndividualBuyerTest extends TestCase
{
    private function resolve(array $stripeBuyer, ?Client $existing = null): Client
    {
        $clients = $this->createMock(ClientRepository::class);
        $clients->method('findOneBy')->willReturn($existing);

        $service = new BillingInvoiceService(
            $this->createMock(InvoiceManager::class),
            $this->createMock(CompanyRepository::class),
            $clients,
            $this->createMock(DocumentSeriesRepository::class),
            $this->createMock(VatRateRepository::class),
            $this->createMock(EuVatRateService::class),
            $this->createMock(EntityManagerInterface::class),
            new NullLogger(),
            null,
        );

        $org = $this->createMock(Organization::class);
        $org->method('getName')->willReturn('Test Org');

        $ref = new \ReflectionMethod($service, 'findOrCreateIndividualBillingClient');

        return $ref->invoke($service, $this->createMock(Company::class), $org, $stripeBuyer);
    }

    public function testCreatesPersonWithoutIdentifiers(): void
    {
        $client = $this->resolve([
            'name' => 'Ion Popescu',
            'email' => 'ion@example.com',
            'address' => ['line1' => 'Str. Exemplu 1', 'line2' => 'ap. 2', 'city' => 'Cluj-Napoca', 'state' => 'Cluj', 'postal_code' => '400000', 'country' => 'ro'],
        ]);

        $this->assertSame('individual', $client->getType());
        $this->assertSame('Ion Popescu', $client->getName());
        $this->assertNull($client->getCui());
        $this->assertNull($client->getCnp());
        $this->assertFalse($client->isVatPayer());
        $this->assertSame('Str. Exemplu 1, ap. 2', $client->getAddress());
        $this->assertSame('Cluj-Napoca', $client->getCity());
        $this->assertSame('Cluj', $client->getCounty());
        $this->assertSame('RO', $client->getCountry());
    }

    public function testFallsBackToOrganizationNameAndRomania(): void
    {
        $client = $this->resolve([]);

        $this->assertSame('Test Org', $client->getName());
        $this->assertSame('RO', $client->getCountry());
        $this->assertNull($client->getAddress());
    }

    public function testKeepsAddressOfExistingClient(): void
    {
        $existing = (new Client())->setType('individual')->setName('Ion Popescu')->setAddress('Str. Veche 5')->setCity('Iasi');

        $client = $this->resolve([
            'email' => 'ion@example.com',
            'address' => ['line1' => 'Str. Noua 9', 'city' => 'Bacau', 'state' => 'Bacau'],
        ], $existing);

        $this->assertSame($existing, $client);
        $this->assertSame('Str. Veche 5', $client->getAddress());
        $this->assertSame('Iasi', $client->getCity());
        $this->assertSame('Bacau', $client->getCounty());
    }
}
