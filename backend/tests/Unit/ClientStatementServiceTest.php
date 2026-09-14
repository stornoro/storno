<?php

namespace App\Tests\Unit;

use App\Entity\Client;
use App\Entity\Company;
use App\Entity\Invoice;
use App\Enum\DocumentStatus;
use App\Enum\InvoiceDirection;
use App\Repository\BankAccountRepository;
use App\Repository\InvoiceRepository;
use App\Service\Client\ClientStatementService;
use PHPUnit\Framework\TestCase;

class ClientStatementServiceTest extends TestCase
{
    private Company $company;
    private Client $client;
    private ClientStatementService $service;

    protected function setUp(): void
    {
        $this->company = new Company();
        $this->company->setName('Firma Test SRL');
        $this->company->setCif(12345678);
        $this->company->setDefaultCurrency('RON');

        $this->client = new Client();
        $this->client->setCompany($this->company);
        $this->client->setName('Client Test SRL');
        $this->client->setCui('RO99999999');
        $this->client->setEmail('client@example.com');

        $bankAccounts = $this->createMock(BankAccountRepository::class);
        $bankAccounts->method('findByCompany')->willReturn([]);

        $this->service = new ClientStatementService(
            $this->createMock(InvoiceRepository::class),
            $bankAccounts,
        );
    }

    private function invoice(string $number, string $issueDate, ?string $dueDate, string $total, string $paid = '0.00', DocumentStatus $status = DocumentStatus::ISSUED, string $currency = 'RON'): Invoice
    {
        $inv = new Invoice();
        $inv->setCompany($this->company);
        $inv->setClient($this->client);
        $inv->setDirection(InvoiceDirection::OUTGOING);
        $inv->setNumber($number);
        $inv->setIssueDate(new \DateTime($issueDate));
        $inv->setDueDate($dueDate ? new \DateTime($dueDate) : null);
        $inv->setTotal($total);
        $inv->setAmountPaid($paid);
        $inv->setStatus($status);
        $inv->setCurrency($currency);

        return $inv;
    }

    public function testDaysOverdueAndBands(): void
    {
        $asOf = new \DateTimeImmutable('2026-09-14');

        self::assertSame(-16, $this->service->daysOverdue($this->invoice('F1', '2026-09-01', '2026-09-30', '10'), $asOf));
        self::assertSame(0, $this->service->daysOverdue($this->invoice('F2', '2026-09-01', '2026-09-14', '10'), $asOf));
        self::assertSame(1, $this->service->daysOverdue($this->invoice('F3', '2026-09-01', '2026-09-13', '10'), $asOf));
        // no due date: due on the issue date
        self::assertSame(14, $this->service->daysOverdue($this->invoice('F4', '2026-08-31', null, '10'), $asOf));

        self::assertSame('current', $this->service->bandFor(-5));
        self::assertSame('current', $this->service->bandFor(0));
        self::assertSame('days1_30', $this->service->bandFor(1));
        self::assertSame('days1_30', $this->service->bandFor(30));
        self::assertSame('days31_60', $this->service->bandFor(31));
        self::assertSame('days61_90', $this->service->bandFor(90));
        self::assertSame('days91_120', $this->service->bandFor(91));
        self::assertSame('days121_180', $this->service->bandFor(180));
        self::assertSame('over180', $this->service->bandFor(181));
        self::assertSame('over180', $this->service->bandFor(400));
    }

    public function testStatementSplitsOutstandingIntoAgingBandsAndComputesBalance(): void
    {
        $asOf = new \DateTimeImmutable('2026-09-14');

        $invoices = [
            $this->invoice('F-1', '2026-09-01', '2026-09-30', '1000.00'),                    // current
            $this->invoice('F-2', '2026-08-01', '2026-08-31', '500.00', '200.00'),           // 14 days -> 1-30, outstanding 300
            $this->invoice('F-3', '2026-06-01', '2026-07-01', '250.00'),                     // 75 days -> 61-90
            $this->invoice('F-4', '2026-01-01', '2026-01-31', '100.00'),                     // 226 days -> >180
            $this->invoice('F-5', '2026-08-01', '2026-08-15', '400.00', '400.00'),           // fully paid, skipped
            $this->invoice('F-6', '2026-08-01', '2026-08-15', '900.00', '0.00', DocumentStatus::DRAFT),   // draft, skipped
            $this->invoice('F-7', '2026-08-01', '2026-08-15', '900.00', '0.00', DocumentStatus::CANCELLED), // cancelled, skipped
            $this->invoice('F-8', '2026-09-20', '2026-10-20', '700.00'),                     // issued after asOf, skipped
            $this->invoice('S-1', '2026-09-05', '2026-09-05', '-50.00'),                     // storno: credit
            $this->invoice('E-1', '2026-08-01', '2026-08-10', '100.00', '0.00', DocumentStatus::ISSUED, 'EUR'), // other currency
        ];

        $s = $this->service->statementFromInvoices($this->client, $invoices, $asOf);

        self::assertSame('2026-09-14', $s['asOf']);
        self::assertSame('RON', $s['currency']);
        self::assertSame('Client Test SRL', $s['client']['name']);

        $numbers = array_column($s['invoices'], 'number');
        self::assertSame(['F-4', 'F-3', 'E-1', 'F-2', 'S-1', 'F-1'], $numbers, 'sorted by due date');

        self::assertSame('1000.00', $s['aging']['current']['amount']);
        self::assertSame('300.00', $s['aging']['days1_30']['amount']);
        self::assertSame('0.00', $s['aging']['days31_60']['amount']);
        self::assertSame('250.00', $s['aging']['days61_90']['amount']);
        self::assertSame('0.00', $s['aging']['days91_120']['amount']);
        self::assertSame('0.00', $s['aging']['days121_180']['amount']);
        self::assertSame('100.00', $s['aging']['over180']['amount']);
        self::assertSame(1, $s['aging']['over180']['count']);

        self::assertSame('1650.00', $s['totals']['outstanding']);
        self::assertSame('650.00', $s['totals']['overdue']);
        self::assertSame('-50.00', $s['totals']['credits']);
        self::assertSame('1600.00', $s['balance']);
        self::assertSame(5, $s['totals']['count']);
        self::assertSame('1800.00', $s['totals']['invoiced']);
        self::assertSame('200.00', $s['totals']['paid']);

        self::assertSame(['EUR' => ['outstanding' => '100.00', 'count' => 1]], $s['otherCurrencies']);

        $f2 = $s['invoices'][array_search('F-2', $numbers, true)];
        self::assertSame(14, $f2['daysOverdue']);
        self::assertSame('days1_30', $f2['band']);
        self::assertSame('300.00', $f2['outstanding']);

        $f1 = $s['invoices'][array_search('F-1', $numbers, true)];
        self::assertSame(0, $f1['daysOverdue']);
        self::assertSame('current', $f1['band']);

        $credit = $s['invoices'][array_search('S-1', $numbers, true)];
        self::assertNull($credit['band']);
        self::assertSame('-50.00', $credit['outstanding']);

        $sum = '0.00';
        foreach ($s['aging'] as $band) {
            $sum = bcadd($sum, $band['amount'], 2);
        }
        self::assertSame($s['totals']['outstanding'], $sum, 'aging bands add up to the outstanding total');
        self::assertSame([], $s['bankAccounts']);
    }

    public function testEmptyStatementHasZeroBalance(): void
    {
        $s = $this->service->statementFromInvoices($this->client, [], new \DateTimeImmutable('2026-09-14'));

        self::assertSame('0.00', $s['balance']);
        self::assertSame([], $s['invoices']);
        self::assertSame(0, $s['totals']['count']);
        self::assertSame(array_keys(ClientStatementService::AGING_BANDS), array_keys($s['aging']));
    }
}
