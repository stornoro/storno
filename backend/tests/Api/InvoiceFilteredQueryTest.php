<?php

namespace App\Tests\Api;

use App\Entity\Company;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Enum\DocumentStatus;
use App\Enum\InvoiceDirection;
use App\Repository\InvoiceRepository;
use App\Service\Report\VatReportService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The export queries limit and batch by invoice, never by joined line row, and the VAT summary
 * sums every invoice of the period. The data sits in 2031 so fixture invoices do not mix in.
 */
class InvoiceFilteredQueryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private InvoiceRepository $repo;
    private Company $company;
    /** @var list<string> */
    private array $created = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->repo = self::getContainer()->get(InvoiceRepository::class);
        $company = $this->em->getRepository(Company::class)->findOneBy([]);
        self::assertNotNull($company, 'fixtures must provide a company');
        $this->company = $company;
    }

    protected function tearDown(): void
    {
        if ($this->created !== []) {
            $this->em->clear();
            foreach ($this->em->getRepository(Invoice::class)->findBy(['id' => $this->created]) as $invoice) {
                $this->em->remove($invoice);
            }
            $this->em->flush();
        }
        parent::tearDown();
    }

    /** @param list<array{string, string, string}> $lines [rate, base, vat] */
    private function invoice(string $date, InvoiceDirection $dir, DocumentStatus $status, string $currency, array $lines, ?array $amounts = null): Invoice
    {
        $inv = new Invoice();
        $inv->setCompany($this->company);
        $inv->setDirection($dir);
        $inv->setStatus($status);
        $inv->setNumber('TEST-' . bin2hex(random_bytes(4)));
        $inv->setIssueDate(new \DateTime($date));
        $inv->setDueDate(new \DateTime($date . ' +30 days'));
        $inv->setCurrency($currency);
        $sub = '0.00';
        $vat = '0.00';
        foreach ($lines as [$rate, $base, $vatAmount]) {
            $line = new InvoiceLine();
            $line->setDescription('Linie test')->setQuantity('1')->setUnitPrice($base)->setVatRate($rate)->setLineTotal($base)->setVatAmount($vatAmount);
            $inv->addLine($line);
            $sub = bcadd($sub, $base, 2);
            $vat = bcadd($vat, $vatAmount, 2);
        }
        [$sub, $vat] = $amounts ?? [$sub, $vat];
        $inv->setSubtotal($sub)->setVatTotal($vat)->setTotal(bcadd($sub, $vat, 2));
        $this->em->persist($inv);
        $this->em->flush();
        $this->created[] = $inv->getId()->toRfc4122();

        return $inv;
    }

    private function seedFiveInvoicesWithThreeLines(): void
    {
        foreach (['2031-04-03', '2031-04-05', '2031-04-05', '2031-04-08', '2031-04-10'] as $date) {
            $this->invoice($date, InvoiceDirection::OUTGOING, DocumentStatus::ISSUED, 'RON', [['21', '10.00', '2.10'], ['21', '20.00', '4.20'], ['9', '30.00', '2.70']]);
        }
        $this->clearEntityManager();
    }

    private function clearEntityManager(): void
    {
        $id = $this->company->getId();
        $this->em->clear();
        $this->company = $this->em->find(Company::class, $id);
    }

    private const APRIL = ['dateFrom' => '2031-04-01', 'dateTo' => '2031-04-30'];

    public function testLimitCountsInvoicesAndReturnsWholeInvoices(): void
    {
        $this->seedFiveInvoicesWithThreeLines();

        $invoices = $this->repo->findByCompanyFiltered($this->company, self::APRIL, 2);

        // A LIMIT on the joined rows would return one invoice with two of its three lines.
        self::assertCount(2, $invoices);
        foreach ($invoices as $invoice) {
            self::assertCount(3, $invoice->getLines());
        }
        self::assertSame('2031-04-10', $invoices[0]->getIssueDate()->format('Y-m-d'));
        self::assertSame('2031-04-08', $invoices[1]->getIssueDate()->format('Y-m-d'));
    }

    public function testIteratorYieldsEveryInvoiceOnceInTheListOrder(): void
    {
        $this->seedFiveInvoicesWithThreeLines();

        $expected = array_map(static fn (Invoice $i) => $i->getId()->toRfc4122(), $this->repo->findByCompanyFiltered($this->company, self::APRIL, null));
        self::assertCount(5, $expected);
        $this->clearEntityManager();

        $seen = [];
        foreach ($this->repo->iterateByCompanyFiltered($this->company, self::APRIL, 2) as $invoice) {
            self::assertCount(3, $invoice->getLines());
            $seen[] = $invoice->getId()->toRfc4122();
        }

        self::assertSame($expected, $seen);
        // Finished batches are detached; the company of the request stays managed.
        foreach ($seen as $id) {
            self::assertFalse($this->em->getUnitOfWork()->tryGetById($id, Invoice::class));
        }
        self::assertTrue($this->em->contains($this->company));
    }

    public function testVatSummaryIsSummedOverEveryInvoice(): void
    {
        $this->invoice('2031-03-02', InvoiceDirection::OUTGOING, DocumentStatus::ISSUED, 'RON', [['21', '100.00', '21.00'], ['9', '50.00', '4.50']]);
        $this->invoice('2031-03-03', InvoiceDirection::OUTGOING, DocumentStatus::DRAFT, 'RON', [['21', '10.00', '2.10']]);
        $this->invoice('2031-03-04', InvoiceDirection::INCOMING, DocumentStatus::VALIDATED, 'EUR', [['21', '200.00', '42.00']]);
        // No lines: the invoice amounts count in the totals, not in a rate bucket.
        $this->invoice('2031-03-05', InvoiceDirection::OUTGOING, DocumentStatus::ISSUED, 'usd', [], ['40.00', '8.40']);
        // Cancelled documents never count.
        $this->invoice('2031-03-06', InvoiceDirection::OUTGOING, DocumentStatus::CANCELLED, 'RON', [['21', '999.00', '209.79']]);
        $this->clearEntityManager();

        $service = self::getContainer()->get(VatReportService::class);
        $period = ['from' => new \DateTimeImmutable('2031-03-01'), 'to' => new \DateTimeImmutable('2031-03-31')];

        $report = $service->generateFor($this->company, $period);
        self::assertSame('200.00', $report['totals']['outgoing']['taxableBase']);
        self::assertSame('36.00', $report['totals']['outgoing']['vatAmount']);
        self::assertSame('236.00', $report['totals']['outgoing']['total']);
        self::assertSame('110.00', $report['outgoing']['21.00']['taxableBase']);
        self::assertSame('23.10', $report['outgoing']['21.00']['vatAmount']);
        self::assertSame('4.50', $report['outgoing']['9.00']['vatAmount']);
        self::assertSame('0.00', $report['outgoing']['5.00']['vatAmount']);
        self::assertSame('42.00', $report['totals']['incoming']['vatAmount']);
        self::assertSame('-6.00', $report['netVat']);
        self::assertSame(['outgoing' => 3, 'incoming' => 1], $report['invoiceCount']);
        self::assertSame(['EUR', 'RON', 'USD'], $report['currencies']);

        $final = $service->generateFor($this->company, $period + ['finalOnly' => true]);
        self::assertSame('190.00', $final['totals']['outgoing']['taxableBase']);
        self::assertSame('100.00', $final['outgoing']['21.00']['taxableBase']);
        self::assertSame(2, $final['invoiceCount']['outgoing']);

        $nine = $service->generateFor($this->company, $period + ['vatRate' => '9']);
        self::assertSame('50.00', $nine['totals']['outgoing']['taxableBase']);
        self::assertSame('4.50', $nine['totals']['outgoing']['vatAmount']);
        self::assertSame('54.50', $nine['totals']['outgoing']['total']);
        self::assertSame('0.00', $nine['outgoing']['21.00']['vatAmount']);
        self::assertSame(['outgoing' => 1, 'incoming' => 0], $nine['invoiceCount']);
        self::assertSame(['RON'], $nine['currencies']);

        $incoming = $service->generateFor($this->company, $period + ['direction' => 'incoming', 'currency' => 'EUR']);
        self::assertSame('0.00', $incoming['totals']['outgoing']['taxableBase']);
        self::assertSame('200.00', $incoming['totals']['incoming']['taxableBase']);
    }
}
