<?php

namespace App\Tests\Unit;

use App\Entity\Company;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\Product;
use App\Entity\Supplier;
use App\Entity\TaxDeclaration;
use App\Enum\DeclarationType;
use App\Enum\InvoiceDirection;
use App\Repository\InvoiceRepository;
use App\Service\Declaration\Populator\D301Populator;
use App\Service\Declaration\XmlGenerator\D301XmlGenerator;
use PHPUnit\Framework\TestCase;

class D301PopulatorTest extends TestCase
{
    private Company $company;

    protected function setUp(): void
    {
        $this->company = new Company();
        $this->company->setName('Firma Test SRL');
        $this->company->setCif(12345678);
        $this->company->setVatPayer(false);
        $this->company->setRepresentative('Popescu Ion');
    }

    /** @param array<int, array{base: string, service?: bool}> $lines */
    private function invoice(string $country, string $issueDate, array $lines, array $opts = []): Invoice
    {
        $inv = new Invoice();
        $inv->setCompany($this->company);
        $inv->setDirection($opts['direction'] ?? InvoiceDirection::INCOMING);
        $inv->setIssueDate(new \DateTime($issueDate));
        $inv->setNumber($opts['number'] ?? 'F-1');
        $inv->setCurrency($opts['currency'] ?? 'RON');
        if (isset($opts['exchangeRate'])) {
            $inv->setExchangeRate($opts['exchangeRate']);
        }
        $supplier = new Supplier();
        $supplier->setCompany($this->company);
        $supplier->setName('Furnizor ' . $country);
        $supplier->setCountry($country);
        $supplier->setCif($opts['cif'] ?? ($country === 'RO' ? '11111111' : 'XX123'));
        $inv->setSupplier($supplier);
        foreach ($lines as $l) {
            $line = new InvoiceLine();
            $line->setDescription('x');
            $line->setQuantity('1');
            $line->setUnitPrice($l['base']);
            $line->setLineTotal($l['base']);
            $line->setVatRate('0');
            $line->setVatAmount('0.00');
            if (!empty($l['service'])) {
                $p = new Product();
                $p->setCompany($this->company);
                $p->setName('serviciu');
                $p->setIsService(true);
                $line->setProduct($p);
            }
            $inv->addLine($line);
        }
        return $inv;
    }

    /** @param Invoice[] $invoices */
    private function populate(array $invoices, int $year = 2026, int $month = 2): array
    {
        $repo = $this->createMock(InvoiceRepository::class);
        $repo->method('findForVatReturn')->willReturn($invoices);
        return (new D301Populator($repo))->populate($this->company, $year, $month, 'monthly');
    }

    public function testEuGoodsAndServicesLandInSections1And4WithThe41SubTotal(): void
    {
        $data = $this->populate([
            $this->invoice('DE', '2026-02-10', [['base' => '1000.00']], ['number' => 'DE-1']),
            $this->invoice('FR', '2026-02-12', [['base' => '200.00', 'service' => true]], ['number' => 'FR-1']),
        ]);
        $types = array_map(static fn (array $s) => $s['tip_operatie'], $data['sections']);
        self::assertSame(['1', '4', '5'], $types);
        self::assertSame('1000', $data['rows']['baza1']);
        self::assertSame('210', $data['rows']['tva1']);
        self::assertSame('200', $data['rows']['baza4']);
        self::assertSame('42', $data['rows']['tva4']);
        self::assertSame('200', $data['rows']['baza5'], 'section 4.1 repeats the EU service row');
        self::assertSame('42', $data['rows']['tva5']);
        self::assertSame('0', $data['rows']['baza2']);
        self::assertSame((string) (1000 + 210 + 200 + 42 + 200 + 42), $data['rows']['totalPlata_A']);
        self::assertSame('252', $data['totals']['toPay']);
        self::assertSame('10.02.2026', $data['sections'][0]['data_doc']);
        self::assertSame('RON', $data['sections'][0]['tip_valuta']);
        self::assertSame('1.0000', $data['sections'][0]['curs_valutar']);
    }

    public function testServicesFromOutsideTheEuAreSection4OnlyAndGoodsAreImports(): void
    {
        $data = $this->populate([
            $this->invoice('US', '2026-02-10', [['base' => '300.00', 'service' => true], ['base' => '50.00']], ['number' => 'US-1']),
        ]);
        self::assertCount(1, $data['sections']);
        self::assertSame('4', $data['sections'][0]['tip_operatie']);
        self::assertSame('300', $data['sections'][0]['baza']);
        self::assertSame('63', $data['sections'][0]['tva']);
        self::assertSame('0', $data['rows']['baza5']);
        self::assertSame(1, $data['invoiceCounts']['imports']);
        self::assertContains('IMPORTS_EXCLUDED', array_column($data['warnings'], 'code'));
    }

    public function testDomesticAndOutgoingInvoicesAreIgnored(): void
    {
        $data = $this->populate([
            $this->invoice('RO', '2026-02-10', [['base' => '1000.00']]),
            $this->invoice('DE', '2026-02-10', [['base' => '1000.00']], ['direction' => InvoiceDirection::OUTGOING]),
        ]);
        self::assertSame([], $data['sections']);
        self::assertSame('0', $data['rows']['totalPlata_A']);
        self::assertContains('NO_OPERATIONS', array_column($data['warnings'], 'code'));
    }

    public function testForeignCurrencyUsesTheInvoiceRateAndRoundsToWholeLei(): void
    {
        $data = $this->populate([
            $this->invoice('IT', '2026-02-10', [['base' => '100.00']], ['currency' => 'EUR', 'exchangeRate' => '4.9750', 'number' => 'IT-7']),
        ]);
        $s = $data['sections'][0];
        self::assertSame('100.00', $s['val_valuta']);
        self::assertSame('EUR', $s['tip_valuta']);
        self::assertSame('4.9750', $s['curs_valutar']);
        self::assertSame('498', $s['baza']);       // 497.50 → 498
        self::assertSame('105', $s['tva']);        // 498 × 21 % = 104.58 → 105
    }

    public function testStandardRateFollowsTheInvoiceDate(): void
    {
        self::assertSame('19', D301Populator::standardRate(new \DateTimeImmutable('2025-07-31')));
        self::assertSame('21', D301Populator::standardRate(new \DateTimeImmutable('2025-08-01')));
        $data = $this->populate([$this->invoice('DE', '2025-07-15', [['base' => '100.00']])], 2025, 7);
        self::assertSame('19', $data['sections'][0]['tva']);
    }

    public function testCurrencyOutsideTheFormListIsConvertedToRon(): void
    {
        $data = $this->populate([
            $this->invoice('DE', '2026-02-10', [['base' => '100.00']], ['currency' => 'CNY', 'exchangeRate' => '0.6500']),
        ]);
        $s = $data['sections'][0];
        self::assertSame('RON', $s['tip_valuta']);
        self::assertSame('65.00', $s['val_valuta']);
        self::assertSame('65', $s['baza']);
        self::assertContains('UNSUPPORTED_CURRENCY', array_column($data['warnings'], 'code'));
    }

    public function testHeaderAndWarnings(): void
    {
        $data = $this->populate([]);
        $r = $data['rows'];
        self::assertSame('0', $r['d_rec']);
        self::assertSame('2', $r['temei']);
        self::assertSame('1', $r['pers_inreg']);
        self::assertSame('Popescu', $r['nume_declarant']);
        self::assertSame('Ion', $r['prenume_declarant']);
        self::assertSame('Administrator', $r['functia_declarant']);
        self::assertContains('MISSING_BANK_ACCOUNT', array_column($data['warnings'], 'code'));

        $this->company->setVatPayer(true);
        self::assertContains('COMPANY_IS_VAT_PAYER', array_column($this->populate([])['warnings'], 'code'));
    }

    public function testPaymentReferenceLayoutAndCheckDigits(): void
    {
        $ref = D301Populator::paymentReference(2026, 2);
        self::assertSame(23, strlen($ref));
        self::assertSame('1030101', substr($ref, 0, 7));
        self::assertSame('0226', substr($ref, 7, 4));       // period MMYY
        self::assertSame('250326', substr($ref, 11, 6));    // due 25.03.26
        self::assertSame('0000', substr($ref, 17, 4));      // mijl_trans + fixed zeros
        self::assertSame(array_sum(array_map('intval', str_split(substr($ref, 0, 21)))), (int) substr($ref, 21, 2));
        self::assertSame('250127', substr(D301Populator::paymentReference(2026, 12), 11, 6));
    }

    public function testXmlCarriesSectionsAndRecomputedTotals(): void
    {
        $data = $this->populate([
            $this->invoice('DE', '2026-02-10', [['base' => '1000.00']], ['number' => 'DE-1']),
            $this->invoice('FR', '2026-02-12', [['base' => '200.00', 'service' => true]], ['number' => 'FR-1']),
        ]);
        $decl = new TaxDeclaration();
        $decl->setCompany($this->company);
        $decl->setType(DeclarationType::D301);
        $decl->setYear(2026);
        $decl->setMonth(2);
        $decl->setPeriodType('monthly');
        $decl->setData($data);

        $xml = (new D301XmlGenerator())->generate($decl);
        $doc = new \DOMDocument();
        self::assertTrue($doc->loadXML($xml));
        $root = $doc->documentElement;
        self::assertSame('declaratie301', $root->tagName);
        self::assertSame('2', $root->getAttribute('luna'));
        self::assertSame('12345678', $root->getAttribute('cif'));
        self::assertSame('1000', $root->getAttribute('baza1'));
        self::assertSame('42', $root->getAttribute('tva5'));
        self::assertSame('1694', $root->getAttribute('totalPlata_A'));
        self::assertSame('Popescu', $root->getAttribute('nume_declarant'));
        self::assertFalse($root->hasAttribute('fax'));
        $sections = $root->getElementsByTagName('sectiune');
        self::assertSame(3, $sections->length);
        self::assertSame('DE-1', $sections->item(0)->getAttribute('nr_doc'));
        self::assertSame('1000.00', $sections->item(0)->getAttribute('val_valuta'));
        self::assertSame('5', $sections->item(2)->getAttribute('tip_operatie'));
    }
}
