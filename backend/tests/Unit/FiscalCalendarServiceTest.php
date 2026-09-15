<?php

namespace App\Tests\Unit;

use App\Entity\Company;
use App\Entity\TaxDeclaration;
use App\Enum\DeclarationStatus;
use App\Enum\DeclarationType;
use App\Entity\Dosar;
use App\Repository\DosarRepository;
use App\Repository\InvoiceRepository;
use App\Repository\TaxDeclarationRepository;
use App\Service\Calendar\FiscalCalendarService;
use App\Service\Calendar\RomanianHolidays;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class FiscalCalendarServiceTest extends TestCase
{
    private InvoiceRepository&MockObject $invoices;
    private TaxDeclarationRepository&MockObject $declarations;
    private DosarRepository&MockObject $dosare;
    private FiscalCalendarService $service;

    protected function setUp(): void
    {
        $this->invoices = $this->createMock(InvoiceRepository::class);
        $this->declarations = $this->createMock(TaxDeclarationRepository::class);
        $this->invoices->method('hasIntraCommunityOperations')->willReturn(false);
        $this->invoices->method('hasForeignSupplierInvoices')->willReturn(false);
        $this->declarations->method('findByCompanyAndStatuses')->willReturn([]);
        $this->dosare = $this->createMock(DosarRepository::class);
        $this->dosare->method('findForCompany')->willReturn([]);
        $this->service = new FiscalCalendarService(new RomanianHolidays(), $this->invoices, $this->declarations, $this->dosare);
    }

    private function company(bool $vatPayer = true, string $vatPeriod = 'monthly', string $incomeTaxPeriod = 'quarterly', bool $employees = false, bool $individual = false): Company
    {
        $company = new Company();
        $company->setName('Firma Test SRL');
        $company->setCif(12345678);
        $company->setVatPayer($vatPayer);
        $company->setVatPeriod($vatPeriod);
        $company->setIncomeTaxPeriod($incomeTaxPeriod);
        $company->setHasEmployees($employees);
        $company->setType($individual ? Company::TYPE_INDIVIDUAL : Company::TYPE_COMPANY);

        return $company;
    }

    /** @return array<string, array<string, mixed>> items keyed by "CODE dueDate" */
    private function keyed(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            $out[$item['code'] . ' ' . $item['dueDate']] = $item;
        }

        return $out;
    }

    public function testMonthlyVatPayerGetsD300D394D100AndSaftInTheWindow(): void
    {
        // from 1 Oct 2026, 60 days: September deadlines (October) and October deadlines (November)
        $items = $this->keyed($this->service->upcoming($this->company(), new \DateTimeImmutable('2026-10-01'), 60));

        self::assertArrayHasKey('D300 2026-10-26', $items, '25 Oct 2026 is a Sunday → Monday 26'); // weekend shift
        self::assertSame(['year' => 2026, 'month' => 9, 'from' => '2026-09-01', 'to' => '2026-09-30'], $items['D300 2026-10-26']['period']);
        self::assertSame('d300', $items['D300 2026-10-26']['declarationType']);
        self::assertSame('vat_payer', $items['D300 2026-10-26']['appliesBecause']);
        self::assertSame('2026-10-25', $items['D300 2026-10-26']['nominalDueDate']);
        self::assertSame(25, $items['D300 2026-10-26']['daysLeft']);
        self::assertArrayHasKey('D394 2026-10-30', $items);
        self::assertArrayHasKey('D406 2026-11-02', $items, '31 Oct 2026 is a Saturday → Monday 2 Nov');
        self::assertArrayHasKey('D300 2026-11-25', $items);
        self::assertArrayHasKey('D100 2026-10-26', $items, 'Q3 income tax is due in October');
        self::assertSame(['year' => 2026, 'quarter' => 3, 'from' => '2026-07-01', 'to' => '2026-09-30'], $items['D100 2026-10-26']['period']);
        self::assertArrayNotHasKey('D100 2026-11-25', $items, 'quarterly income tax has nothing due in November');
        self::assertArrayNotHasKey('D112 2026-10-26', $items, 'no employees');
        self::assertArrayNotHasKey('D390 2026-10-26', $items, 'no intra-community operations');
        self::assertArrayNotHasKey('D301 2026-10-26', $items, 'VAT payers do not file D301');
        // September deadlines (October window start) come out ordered by due date
        $dates = array_map(static fn (array $i) => $i['dueDate'], array_values($items));
        $sorted = $dates;
        sort($sorted);
        self::assertSame($sorted, $dates);
    }

    public function testQuarterlyVatPeriodOnlyProducesVatDeadlinesAfterAQuarterEnd(): void
    {
        $company = $this->company(vatPeriod: 'quarterly');
        $items = $this->keyed($this->service->upcoming($company, new \DateTimeImmutable('2026-10-01'), 60));

        self::assertArrayHasKey('D300 2026-10-26', $items);
        self::assertSame(['year' => 2026, 'quarter' => 3, 'from' => '2026-07-01', 'to' => '2026-09-30'], $items['D300 2026-10-26']['period']);
        self::assertArrayHasKey('D394 2026-10-30', $items);
        self::assertArrayHasKey('D406 2026-11-02', $items);
        self::assertArrayNotHasKey('D300 2026-11-25', $items);
        self::assertArrayNotHasKey('D394 2026-11-30', $items);
        self::assertArrayNotHasKey('D406 2026-11-30', $items);
    }

    public function testHolidayShiftsTheDeadlineToTheNextWorkingDay(): void
    {
        // 30 Nov (Sf. Andrei, Monday) and 1 Dec 2026 (Tuesday) are holidays → D394 for October is due 2 Dec
        $items = $this->keyed($this->service->upcoming($this->company(), new \DateTimeImmutable('2026-11-10'), 30));
        self::assertArrayHasKey('D394 2026-12-02', $items);
        self::assertSame('2026-11-30', $items['D394 2026-12-02']['nominalDueDate']);
        // 25 Dec 2026 Friday (Christmas), 26 Saturday, 27 Sunday → 28 December for the November decont
        $items = $this->keyed($this->service->upcoming($this->company(), new \DateTimeImmutable('2026-12-01'), 30));
        self::assertArrayHasKey('D300 2026-12-28', $items);
    }

    public function testEmployeesAddD112EveryMonth(): void
    {
        $items = $this->keyed($this->service->upcoming($this->company(employees: true), new \DateTimeImmutable('2026-10-01'), 60));
        self::assertArrayHasKey('D112 2026-10-26', $items);
        self::assertArrayHasKey('D112 2026-11-25', $items);
        self::assertSame('employees', $items['D112 2026-11-25']['appliesBecause']);
    }

    public function testIntraCommunityOperationsAddD390ForThatMonthOnly(): void
    {
        $invoices = $this->createMock(InvoiceRepository::class);
        $invoices->method('hasForeignSupplierInvoices')->willReturn(false);
        $invoices->method('hasIntraCommunityOperations')->willReturnCallback(
            static fn (Company $c, \DateTimeInterface $from) => $from->format('Y-m') === '2026-09',
        );
        $service = new FiscalCalendarService(new RomanianHolidays(), $invoices, $this->declarations, $this->dosare);
        $items = $this->keyed($service->upcoming($this->company(), new \DateTimeImmutable('2026-10-01'), 60));

        self::assertArrayHasKey('D390 2026-10-26', $items);
        self::assertSame('intra_community_operations', $items['D390 2026-10-26']['appliesBecause']);
        self::assertArrayNotHasKey('D390 2026-11-25', $items);
    }

    public function testNonVatPayerWithForeignSuppliersFilesD301AndQuarterlySaft(): void
    {
        $invoices = $this->createMock(InvoiceRepository::class);
        $invoices->method('hasIntraCommunityOperations')->willReturn(true); // ignored: not a VAT payer
        $invoices->method('hasForeignSupplierInvoices')->willReturn(true);
        $service = new FiscalCalendarService(new RomanianHolidays(), $invoices, $this->declarations, $this->dosare);
        $items = $this->keyed($service->upcoming($this->company(vatPayer: false), new \DateTimeImmutable('2026-10-01'), 60));

        self::assertArrayHasKey('D301 2026-10-26', $items);
        self::assertArrayHasKey('D301 2026-11-25', $items);
        self::assertArrayNotHasKey('D300 2026-10-26', $items);
        self::assertArrayNotHasKey('D390 2026-10-26', $items);
        self::assertArrayNotHasKey('D394 2026-10-30', $items);
        self::assertArrayHasKey('D406 2026-11-02', $items, 'SAF-T is quarterly without VAT registration');
        self::assertSame(['year' => 2026, 'quarter' => 3, 'from' => '2026-07-01', 'to' => '2026-09-30'], $items['D406 2026-11-02']['period']);
        self::assertArrayNotHasKey('D406 2026-11-30', $items);
    }

    public function testIndividualOnlyGetsTheAnnualReturnAndEmployeeDeclarations(): void
    {
        $items = $this->keyed($this->service->upcoming($this->company(individual: true), new \DateTimeImmutable('2027-04-01'), 90));

        self::assertArrayHasKey('D212 2027-05-25', $items);
        self::assertSame(['year' => 2026, 'from' => '2026-01-01', 'to' => '2026-12-31'], $items['D212 2027-05-25']['period']);
        self::assertSame('d212', $items['D212 2027-05-25']['declarationType']);
        self::assertCount(1, $items, 'no D300/D100/D406/BILANT for a person');

        $company = $this->keyed($this->service->upcoming($this->company(), new \DateTimeImmutable('2027-04-01'), 90));
        self::assertArrayHasKey('BILANT 2027-05-31', $company, '31 May 2027 is a Monday');
        self::assertNull($company['BILANT 2027-05-31']['declarationType']);
        self::assertArrayNotHasKey('D212 2027-05-25', $company);
        $items2026 = $this->keyed($this->service->upcoming($this->company(), new \DateTimeImmutable('2026-05-01'), 40));
        self::assertArrayHasKey('BILANT 2026-05-29', $items2026, '31 May 2026 is a Sunday → the last working day is Friday 29');
    }

    public function testFiledAndOverdueStatuses(): void
    {
        $filed = static function (DeclarationType $type, int $year, int $month, DeclarationStatus $status, string $periodType = 'monthly'): TaxDeclaration {
            $d = new TaxDeclaration();
            $d->setType($type);
            $d->setYear($year);
            $d->setMonth($month);
            $d->setPeriodType($periodType);
            $d->setStatus($status);

            return $d;
        };
        $declarations = $this->createMock(TaxDeclarationRepository::class);
        $declarations->method('findByCompanyAndStatuses')->willReturn([
            $filed(DeclarationType::D300, 2026, 9, DeclarationStatus::ACCEPTED),
            $filed(DeclarationType::D100, 2026, 8, DeclarationStatus::SUBMITTED, 'quarterly'), // any month of Q3 marks the quarter
        ]);
        $service = new FiscalCalendarService(new RomanianHolidays(), $this->invoices, $declarations, $this->dosare);

        $items = $this->keyed($service->upcoming($this->company(employees: true), new \DateTimeImmutable('2026-11-05'), 30));

        self::assertSame('filed', $items['D300 2026-10-26']['status']);
        self::assertSame('filed', $items['D100 2026-10-26']['status']);
        self::assertSame('overdue', $items['D394 2026-10-30']['status'], 'past due and not filed');
        self::assertSame('overdue', $items['D112 2026-10-26']['status']);
        self::assertArrayNotHasKey('D406 2026-11-02', $items, 'a past deadline Storno cannot see filed (SAF-T) is dropped instead of shown overdue');
        self::assertSame('due', $items['D300 2026-11-25']['status']);
        self::assertSame(-10, $items['D300 2026-10-26']['daysLeft']);
        self::assertSame(20, $items['D300 2026-11-25']['daysLeft']);
    }

    public function testDraftAndRejectedDeclarationsDoNotCountAsFiled(): void
    {
        $declarations = $this->createMock(TaxDeclarationRepository::class);
        $declarations->expects(self::once())->method('findByCompanyAndStatuses')
            ->with(self::anything(), [DeclarationStatus::SUBMITTED, DeclarationStatus::PROCESSING, DeclarationStatus::ACCEPTED])
            ->willReturn([]);
        $service = new FiscalCalendarService(new RomanianHolidays(), $this->invoices, $declarations, $this->dosare);
        $items = $this->keyed($service->upcoming($this->company(), new \DateTimeImmutable('2026-11-05'), 30));
        self::assertSame('overdue', $items['D300 2026-10-26']['status']);
    }
    private function rentalDosar(string $deLa, string $panaLa, ?string $deadline): Dosar
    {
        $dosar = (new Dosar())->setType(Dosar::TYPE_RENTAL_CONTRACT)->setTitle('Contract de închiriere Bld. Iuliu Maniu 7')
            ->setSubject(['numar' => '1', 'data' => $deLa, 'deLa' => $deLa, 'panaLa' => $panaLa, 'chirie' => '500', 'moneda' => 'EUR']);
        if ($deadline !== null) {
            $dosar->setDeadlineAt(new \DateTimeImmutable($deadline))->setDeadlineLabel('C168 înregistrare: 30 de zile de la semnarea contractului');
        }

        return $dosar;
    }

    public function testRentalDosarAddsC168EstimateAndContractEndForAnIndividual(): void
    {
        $dosare = $this->createMock(DosarRepository::class);
        $dosare->method('findForCompany')->willReturn([$this->rentalDosar('01.08.2026', '31.07.2027', '2026-08-31')]);
        $service = new FiscalCalendarService(new RomanianHolidays(), $this->invoices, $this->declarations, $dosare);

        // 20 Aug 2026: both 30-day deadlines are ahead
        $items = $this->keyed($service->upcoming($this->company(individual: true), new \DateTimeImmutable('2026-08-20'), 60));
        $this->assertArrayHasKey('C168 2026-08-31', $items);
        $this->assertSame('c168', $items['C168 2026-08-31']['declarationType']);
        $this->assertSame('due', $items['C168 2026-08-31']['status']);
        $this->assertSame('rental_contract', $items['C168 2026-08-31']['appliesBecause']);
        $this->assertSame('Contract de închiriere Bld. Iuliu Maniu 7', $items['C168 2026-08-31']['dosarTitle']);
        $this->assertArrayHasKey('D212_ESTIMAT 2026-08-31', $items, 'estimated income: contract start + 30 days');
        $this->assertSame('d212', $items['D212_ESTIMAT 2026-08-31']['declarationType']);

        // 15 Sep 2026: both are overdue and still listed (inside the lookback)
        $items = $this->keyed($service->upcoming($this->company(individual: true), new \DateTimeImmutable('2026-09-15'), 60));
        $this->assertSame('overdue', $items['C168 2026-08-31']['status']);
        $this->assertSame('overdue', $items['D212_ESTIMAT 2026-08-31']['status']);

        // June 2027: the contract's end is ahead; an ended contract is never reported overdue
        $items = $this->keyed($service->upcoming($this->company(individual: true), new \DateTimeImmutable('2027-06-15'), 60));
        $this->assertArrayHasKey('CONTRACT_END 2027-07-31', $items);
        $this->assertNull($items['CONTRACT_END 2027-07-31']['declarationType']);
        $items = $this->keyed($service->upcoming($this->company(individual: true), new \DateTimeImmutable('2027-08-10'), 60));
        $this->assertArrayNotHasKey('CONTRACT_END 2027-07-31', $items);
    }

    public function testRentalDosarOfACompanyHasNoEstimateAndNoC168OnceAccepted(): void
    {
        $dosare = $this->createMock(DosarRepository::class);
        $dosare->method('findForCompany')->willReturn([$this->rentalDosar('01.08.2026', '31.07.2027', null)]);
        $service = new FiscalCalendarService(new RomanianHolidays(), $this->invoices, $this->declarations, $dosare);

        $codes = array_column($service->upcoming($this->company(), new \DateTimeImmutable('2026-08-20'), 60), 'code');
        $this->assertNotContains('C168', $codes, 'no deadline on the dosar (registration accepted)');
        $this->assertNotContains('D212_ESTIMAT', $codes, 'companies do not file the Declarația unică');
    }
}
