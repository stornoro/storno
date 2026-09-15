<?php

namespace App\Tests\Unit;

use App\Entity\Client;
use App\Entity\Company;
use App\Entity\Supplier;
use App\Repository\ClientRepository;
use App\Repository\SupplierRepository;
use App\Service\Anaf\AnafRateLimiter;
use App\Service\Partner\PartnerStatusNotifier;
use App\Service\Partner\PartnerVerificationService;
use App\Service\Vies\ViesService;
use App\Services\AnafService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class PartnerVerificationServiceTest extends TestCase
{
    private const CUI = '99999901';

    private function anafFound(array $overrides = []): string
    {
        $entry = array_replace_recursive([
            'date_generale' => [
                'cui' => (int) self::CUI, 'denumire' => 'EXEMPLU SRL', 'adresa' => 'STR. EXEMPLU 1', 'nrRegCom' => 'J40/1/2020',
                'telefon' => '', 'codPostal' => '010101', 'stare_inregistrare' => 'INREGISTRAT din data 01.01.2020',
                'statusRO_e_Factura' => true, 'data_inreg_Reg_RO_e_Factura' => '2024-01-01', 'iban' => '', 'organFiscalCompetent' => '',
                'forma_juridica' => '', 'forma_de_proprietate' => '', 'forma_organizare' => '', 'cod_CAEN' => '6201', 'data_inregistrare' => '2020-01-01',
            ],
            'adresa_sediu_social' => ['scod_JudetAuto' => 'B', 'sdenumire_Localitate' => 'Bucuresti Sectorul 1', 'scod_Postal' => '010101'],
            'inregistrare_scop_Tva' => ['scpTVA' => true, 'perioade_TVA' => [['data_inceput_ScpTVA' => '2020-01-01', 'data_sfarsit_ScpTVA' => ' ']]],
            'inregistrare_RTVAI' => ['statusTvaIncasare' => false, 'dataInceputTvaInc' => ' ', 'dataSfarsitTvaInc' => ' '],
            'stare_inactiv' => ['statusInactivi' => false, 'dataInactivare' => ' ', 'dataReactivare' => ' ', 'dataRadiere' => ' '],
            'inregistrare_SplitTVA' => ['statusSplitTVA' => false],
        ], $overrides);

        return json_encode(['cod' => 200, 'message' => 'SUCCESS', 'found' => [$entry], 'notFound' => []]);
    }

    private function anafNotFound(): string
    {
        return json_encode(['cod' => 200, 'message' => 'SUCCESS', 'found' => [], 'notFound' => [(int) self::CUI]]);
    }

    /** @return array{0: PartnerVerificationService, 1: PartnerStatusNotifier&\PHPUnit\Framework\MockObject\MockObject} */
    private function makeService(MockHttpClient $anafHttp, ?MockHttpClient $viesHttp = null, array $stale = []): array
    {
        $notifier = $this->createMock(PartnerStatusNotifier::class);
        $clientRepo = $this->createMock(ClientRepository::class);
        $clientRepo->method('findStaleForVerification')->willReturn(array_values(array_filter($stale, fn ($p) => $p instanceof Client)));
        $supplierRepo = $this->createMock(SupplierRepository::class);
        $supplierRepo->method('findStaleForVerification')->willReturn(array_values(array_filter($stale, fn ($p) => $p instanceof Supplier)));

        $service = new PartnerVerificationService(
            new AnafService($anafHttp, new NullLogger()),
            new ViesService($viesHttp ?? new MockHttpClient(), new NullLogger()),
            $this->createMock(AnafRateLimiter::class),
            $this->createMock(EntityManagerInterface::class),
            $clientRepo,
            $supplierRepo,
            $notifier,
            new NullLogger(),
        );

        return [$service, $notifier];
    }

    private function romanianClient(): Client
    {
        $client = new Client();
        $client->setCompany(new Company());
        $client->setType('company');
        $client->setName('Exemplu SRL');
        $client->setCui(self::CUI);
        $client->setCountry('RO');
        $client->setIsVatPayer(true);

        return $client;
    }

    public function testAnafSnapshotIsStoredOnTheClient(): void
    {
        [$service, $notifier] = $this->makeService(new MockHttpClient(new MockResponse($this->anafFound([
            'inregistrare_RTVAI' => ['statusTvaIncasare' => true, 'dataInceputTvaInc' => '2025-03-01', 'dataSfarsitTvaInc' => ' '],
        ]))));
        $notifier->expects($this->once())->method('notify')
            ->with($this->isInstanceOf(Client::class), [PartnerVerificationService::CHANGE_VAT_ON_COLLECTION]);

        $client = $this->romanianClient();
        $result = $service->verify($client);

        self::assertTrue($result['checked']);
        self::assertSame('anaf', $result['source']);
        self::assertNull($result['error']);
        self::assertSame([PartnerVerificationService::CHANGE_VAT_ON_COLLECTION], $result['changes']);
        self::assertTrue($client->isVatRegistered());
        self::assertTrue($client->isVatOnCollection());
        self::assertSame('2025-03-01', $client->getVatOnCollectionFrom()?->format('Y-m-d'));
        self::assertNull($client->getVatOnCollectionTo());
        self::assertFalse($client->isInactive());
        self::assertTrue($client->isEfacturaRegistered());
        self::assertNotNull($client->getVatStatusCheckedAt());
        self::assertStringContainsString('TVA la incasare din 01.03.2025', (string) $client->getVerificationNotes());
        // invoicing settings are never touched by a verification
        self::assertTrue($client->isVatPayer());
    }

    public function testInactiveAndLostVatAreReportedAndNotified(): void
    {
        [$service, $notifier] = $this->makeService(new MockHttpClient(new MockResponse($this->anafFound([
            'inregistrare_scop_Tva' => ['scpTVA' => false],
            'stare_inactiv' => ['statusInactivi' => true, 'dataInactivare' => '2026-02-10'],
        ]))));
        $notifier->expects($this->once())->method('notify')->with(
            $this->isInstanceOf(Client::class),
            [PartnerVerificationService::CHANGE_BECAME_INACTIVE, PartnerVerificationService::CHANGE_LOST_VAT],
        );

        $client = $this->romanianClient(); // isVatPayer = true → losing VAT is a change even on the first check
        $result = $service->verify($client);

        self::assertSame([PartnerVerificationService::CHANGE_BECAME_INACTIVE, PartnerVerificationService::CHANGE_LOST_VAT], $result['changes']);
        self::assertTrue($client->isInactive());
        self::assertFalse($client->isVatRegistered());
        self::assertStringContainsString('Contribuabil inactiv din 10.02.2026', (string) $client->getVerificationNotes());
        self::assertStringContainsString('neinregistrat in scopuri de TVA', (string) $client->getVerificationNotes());
    }

    public function testSecondCheckWithoutChangesDoesNotNotify(): void
    {
        [$service, $notifier] = $this->makeService(new MockHttpClient([
            new MockResponse($this->anafFound(['stare_inactiv' => ['statusInactivi' => true]])),
            new MockResponse($this->anafFound(['stare_inactiv' => ['statusInactivi' => true]])),
        ]));
        $notifier->expects($this->once())->method('notify');

        $client = $this->romanianClient();
        self::assertSame([PartnerVerificationService::CHANGE_BECAME_INACTIVE], $service->verify($client)['changes']);
        self::assertSame([], $service->verify($client)['changes']);
    }

    public function testAnafOutageKeepsThePreviousSnapshotAndDoesNotFail(): void
    {
        [$service, $notifier] = $this->makeService(new MockHttpClient(new MockResponse('', ['error' => new \RuntimeException('timeout')])));
        $notifier->expects($this->never())->method('notify');

        $client = $this->romanianClient();
        $client->setInactive(false)->setVatRegistered(true);
        $result = $service->verify($client);

        self::assertFalse($result['checked']);
        self::assertSame('registry_unavailable', $result['error']);
        self::assertTrue($client->isVatRegistered());
        self::assertNull($client->getVatStatusCheckedAt());
        self::assertStringContainsString('nu a raspuns', (string) $client->getVerificationNotes());
    }

    public function testUnknownCuiIsMarkedCheckedWithANote(): void
    {
        [$service] = $this->makeService(new MockHttpClient(new MockResponse($this->anafNotFound())));

        $client = $this->romanianClient();
        $result = $service->verify($client);

        self::assertTrue($result['checked']);
        self::assertSame('not_found', $result['error']);
        self::assertNotNull($client->getVatStatusCheckedAt());
        self::assertStringContainsString('negasit', (string) $client->getVerificationNotes());
    }

    public function testEuSupplierGoesToVies(): void
    {
        $vies = new MockHttpClient(new MockResponse(json_encode(['countryCode' => 'DE', 'vatNumber' => '123456789', 'valid' => true, 'name' => 'BEISPIEL GMBH', 'address' => '---'])));
        [$service, $notifier] = $this->makeService(new MockHttpClient(), $vies);
        $notifier->expects($this->never())->method('notify');

        $supplier = new Supplier();
        $supplier->setCompany(new Company());
        $supplier->setName('Beispiel GmbH');
        $supplier->setCountry('DE');
        $supplier->setVatCode('DE123456789');
        $result = $service->verify($supplier);

        self::assertTrue($result['checked']);
        self::assertSame('vies', $result['source']);
        self::assertTrue($supplier->isViesValid());
        self::assertTrue($supplier->isVatRegistered());
        self::assertNotNull($supplier->getVatStatusCheckedAt());
    }

    public function testViesInvalidAfterValidNotifies(): void
    {
        $vies = new MockHttpClient(new MockResponse(json_encode(['valid' => false, 'name' => '---', 'address' => '---'])));
        [$service, $notifier] = $this->makeService(new MockHttpClient(), $vies);
        $notifier->expects($this->once())->method('notify')->with($this->isInstanceOf(Client::class), [PartnerVerificationService::CHANGE_VIES_INVALID]);

        $client = new Client();
        $client->setCompany(new Company());
        $client->setType('company');
        $client->setName('Exemple SAS');
        $client->setCountry('FR');
        $client->setVatCode('FR12345678901');
        $client->setViesValid(true);
        $service->verify($client);

        self::assertFalse($client->isViesValid());
        self::assertFalse($client->isVatRegistered());
    }

    public function testViesOutageSetsNullAndANote(): void
    {
        $vies = new MockHttpClient(new MockResponse('', ['error' => new \RuntimeException('down')]));
        [$service] = $this->makeService(new MockHttpClient(), $vies);

        $client = new Client();
        $client->setCompany(new Company());
        $client->setType('company');
        $client->setName('Exemple SAS');
        $client->setCountry('FR');
        $client->setVatCode('FR12345678901');
        $client->setViesValid(true);
        $result = $service->verify($client);

        self::assertFalse($result['checked']);
        self::assertSame('registry_unavailable', $result['error']);
        self::assertNull($client->isViesValid());
        self::assertStringContainsString('VIES', (string) $client->getVerificationNotes());
    }

    public function testIndividualsAndNonEuPartnersAreNotApplicable(): void
    {
        [$service] = $this->makeService(new MockHttpClient());

        $individual = new Client();
        $individual->setType('individual')->setName('Ion Exemplu')->setCountry('RO');
        self::assertSame('not_applicable', $service->verify($individual)['error']);

        $overseas = new Supplier();
        $overseas->setName('Overseas Inc')->setCountry('US')->setCif('12-3456789');
        self::assertSame('not_applicable', $service->verify($overseas)['error']);
        self::assertNull($service->registryFor($overseas));
    }

    public function testVerifyAllBatchesRomanianPartnersAndCounts(): void
    {
        $a = $this->romanianClient();
        $b = $this->romanianClient();
        $b->setCui('99999902');
        $c = $this->romanianClient();
        $c->setType('individual');
        $c->setCui(null);

        $batchAnswer = json_decode($this->anafFound(), true);
        $second = $batchAnswer['found'][0];
        $second['date_generale']['cui'] = 99999902;
        $second['stare_inactiv']['statusInactivi'] = true;
        $batchAnswer['found'][] = $second;

        $anafHttp = new MockHttpClient(function (string $method, string $url, array $options) use ($batchAnswer): MockResponse {
            $body = json_decode($options['body'], true);
            self::assertCount(2, $body, 'both CUIs go to ANAF in one request');

            return new MockResponse(json_encode($batchAnswer));
        });
        [$service, $notifier] = $this->makeService($anafHttp, null, [$a, $b, $c]);
        $notifier->expects($this->once())->method('notify')->with($b, [PartnerVerificationService::CHANGE_BECAME_INACTIVE]);

        $counts = $service->verifyAll(new Company());

        self::assertSame(['checked' => 2, 'changed' => 1, 'failed' => 0, 'skipped' => 1], $counts);
        self::assertFalse($a->isInactive());
        self::assertTrue($b->isInactive());
        self::assertNull($c->getVatStatusCheckedAt());
    }
}
