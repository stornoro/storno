<?php

namespace App\Tests\Unit;

use App\Entity\Client;
use App\Entity\Company;
use App\Entity\DocumentSeries;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\Product;
use App\Entity\Supplier;
use App\Enum\InvoiceDirection;
use App\Enum\InvoiceTypeCode;
use App\Repository\DocumentSeriesRepository;
use App\Repository\InvoiceRepository;
use App\Service\Declaration\D394\D394Rules;
use App\Service\Declaration\Populator\D394Populator;
use PHPUnit\Framework\TestCase;

class D394PopulatorTest extends TestCase
{
    private Company $company;

    protected function setUp(): void
    {
        $this->company = new Company();
        $this->company->setName('Firma Test SRL');
        $this->company->setCif(self::cui('3138536'));
        $this->company->setAddress('Str. Exemplu 1');
        $this->company->setCity('Bacau');
        $this->company->setState('BC');
        $this->company->setPhone('0234000000');
        $this->company->setCaenCode('6201');
        $this->company->setRepresentative('Popescu Ion');
        $this->company->setRepresentativeRole('Administrator');
    }

    /** A checksum-valid CUI built from the given digits (placeholder, not a real company). */
    private static function cui(string $body): int
    {
        $padded = str_pad($body, 9, '0', STR_PAD_LEFT);
        $weights = [7, 5, 3, 2, 1, 7, 5, 3, 2];
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += ((int) $padded[$i]) * $weights[$i];
        }
        $check = ($sum * 10) % 11;
        return (int) ($body . ($check === 10 ? 0 : $check));
    }

    private function invoice(string $direction, array $lines, array $opts = []): Invoice
    {
        static $n = 0;
        $n++;
        $inv = new Invoice();
        $inv->setCompany($this->company);
        $inv->setDirection($direction === 'out' ? InvoiceDirection::OUTGOING : InvoiceDirection::INCOMING);
        $inv->setIssueDate(new \DateTime($opts['date'] ?? '2026-08-10'));
        $inv->setCurrency($opts['currency'] ?? 'RON');
        $inv->setNumber($opts['number'] ?? sprintf('FT%04d', $n));
        if (isset($opts['exchangeRate'])) {
            $inv->setExchangeRate($opts['exchangeRate']);
        }
        if (isset($opts['type'])) {
            $inv->setInvoiceTypeCode($opts['type']);
        }
        if ($direction === 'out') {
            $client = new Client();
            $client->setCompany($this->company);
            $client->setName($opts['name'] ?? 'Client SRL');
            $client->setType($opts['individual'] ?? false ? 'individual' : 'company');
            $client->setCountry($opts['country'] ?? 'RO');
            $client->setCui($opts['cui'] ?? null);
            $client->setCnp($opts['cnp'] ?? null);
            $client->setVatCode($opts['vatCode'] ?? null);
            $client->setIsVatPayer($opts['vatPayer'] ?? true);
            $client->setCounty($opts['county'] ?? null);
            $client->setCity($opts['city'] ?? 'Bacau');
            $inv->setClient($client);
            $inv->setReceiverCif($opts['cui'] ?? null);
            $inv->setReceiverName($client->getName());
        } else {
            $supplier = new Supplier();
            $supplier->setCompany($this->company);
            $supplier->setName($opts['name'] ?? 'Furnizor SRL');
            $supplier->setCountry($opts['country'] ?? 'RO');
            $supplier->setCif($opts['cui'] ?? null);
            $supplier->setIsVatPayer($opts['vatPayer'] ?? true);
            $inv->setSupplier($supplier);
            $inv->setSenderCif($opts['cui'] ?? null);
            $inv->setSenderName($supplier->getName());
        }
        foreach ($lines as $l) {
            $line = new InvoiceLine();
            $line->setDescription($l['d'] ?? 'x');
            $line->setQuantity('1');
            $line->setUnitPrice($l['base']);
            $line->setLineTotal($l['base']);
            $line->setVatRate($l['rate']);
            $line->setVatCategoryCode($l['cat'] ?? 'S');
            $line->setVatAmount($l['vat'] ?? bcdiv(bcmul($l['base'], $l['rate'], 4), '100', 2));
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
    private function populate(array $invoices, int $year = 2026, int $month = 8, string $period = 'monthly', array $series = []): array
    {
        $repo = $this->createMock(InvoiceRepository::class);
        $repo->method('findForVatReturn')->willReturn($invoices);
        $seriesRepo = $this->createMock(DocumentSeriesRepository::class);
        $seriesRepo->method('findByCompany')->willReturn($series);
        return (new D394Populator($repo, $seriesRepo))->populate($this->company, $year, $month, $period);
    }

    private static function row(array $data, string $tip, ?string $cuiP, int $cota = 0): ?array
    {
        foreach ($data['partners'] as $row) {
            if ($row['tip'] === $tip && $row['cuiP'] === $cuiP && $row['cota'] === $cota) {
                return $row;
            }
        }
        return null;
    }

    public function testRulesHelpers(): void
    {
        self::assertTrue(D394Rules::isValidCui((string) self::cui('1234567')));
        self::assertFalse(D394Rules::isValidCui('12345678'));
        self::assertTrue(D394Rules::isValidCnp('1800101221144'));
        self::assertFalse(D394Rules::isValidCnp('1800101221145'));
        self::assertSame('40', D394Rules::countyCode('B'));
        self::assertSame('04', D394Rules::countyCode('bc'));
        self::assertSame('51', D394Rules::countyCode('CL'));
        self::assertNull(D394Rules::countyCode('ZZ'));
        self::assertSame(1, D394Rules::partnerType('RO', true));
        self::assertSame(2, D394Rules::partnerType('RO', false));
        self::assertSame(3, D394Rules::partnerType('DE', true));
        self::assertSame(4, D394Rules::partnerType('US', true));
        self::assertSame(['L', 'A', 'AI', 'C'], D394Rules::rezumat1Groups(1, 21));
        self::assertSame(['L'], D394Rules::rezumat1Groups(2, 21));
        self::assertSame(['L', 'C'], D394Rules::rezumat1Groups(3, 21));
        self::assertSame(['LS', 'AS', 'V'], D394Rules::rezumat1Groups(1, 0));
        self::assertSame(['LS', 'N'], D394Rules::rezumat1Groups(2, 0));
        self::assertSame(['LS'], D394Rules::rezumat1Groups(4, 0));
    }

    public function testDomesticSalesAndPurchasesAreAggregatedPerPartnerTypeAndRate(): void
    {
        $clientCui = (string) self::cui('1111111');
        $supplierCui = (string) self::cui('2222222');
        $data = $this->populate([
            $this->invoice('out', [['base' => '1000.00', 'rate' => '21'], ['base' => '100.00', 'rate' => '0', 'cat' => 'E']], ['cui' => $clientCui]),
            $this->invoice('out', [['base' => '500.50', 'rate' => '21']], ['cui' => $clientCui]),
            $this->invoice('out', [['base' => '200.00', 'rate' => '11']], ['cui' => $clientCui]),
            $this->invoice('in', [['base' => '300.00', 'rate' => '21'], ['base' => '50.00', 'rate' => '0', 'cat' => 'E']], ['cui' => $supplierCui]),
        ]);

        $l21 = self::row($data, 'L', $clientCui, 21);
        self::assertNotNull($l21);
        self::assertSame(1, $l21['tip_partener']);
        self::assertSame(2, $l21['nrFact']);
        self::assertSame(1501, $l21['baza']); // 1500.50 rounded half up
        self::assertSame(315, $l21['tva']);   // 315.105
        self::assertSame(200, self::row($data, 'L', $clientCui, 11)['baza']);
        self::assertSame(22, self::row($data, 'L', $clientCui, 11)['tva']);
        $ls = self::row($data, 'LS', $clientCui);
        self::assertSame(100, $ls['baza']);
        self::assertNull($ls['tva'], 'LS rows carry no VAT attribute');
        self::assertSame(300, self::row($data, 'A', $supplierCui, 21)['baza']);
        self::assertSame(63, self::row($data, 'A', $supplierCui, 21)['tva']);
        self::assertSame(50, self::row($data, 'AS', $supplierCui)['baza']);

        // rezumat1: one row per (tip_partener, cota) with every group the validator wants for that pair
        $rez = [];
        foreach ($data['rezumat1'] as $r) {
            $rez[$r['tip_partener'] . '|' . $r['cota']] = $r;
        }
        self::assertSame([1501, 315, 2], [$rez['1|21']['bazaL'], $rez['1|21']['tvaL'], $rez['1|21']['facturiL']]);
        self::assertSame([300, 63, 1], [$rez['1|21']['bazaA'], $rez['1|21']['tvaA'], $rez['1|21']['facturiA']]);
        self::assertSame(0, $rez['1|21']['facturiAI']);
        self::assertSame(0, $rez['1|21']['bazaC']);
        self::assertArrayNotHasKey('facturiLS', $rez['1|21']);
        self::assertSame(100, $rez['1|0']['bazaLS']);
        self::assertSame(50, $rez['1|0']['bazaAS']);
        self::assertSame(0, $rez['1|0']['facturiV']);
        self::assertArrayNotHasKey('facturiL', $rez['1|0']);
        self::assertArrayNotHasKey('facturiN', $rez['1|0']);

        // rezumat2: one per non-zero rate, all partner types together
        $rez2 = [];
        foreach ($data['rezumat2'] as $r) {
            $rez2[$r['cota']] = $r;
        }
        self::assertSame([21, 11], array_keys($rez2));
        self::assertSame(1501, $rez2[21]['bazaL']);
        self::assertSame(300, $rez2[21]['bazaA']);
        self::assertSame(0, $rez2[21]['bazaAI']);
        self::assertSame(0, $rez2[21]['baza_incasari_i1']);
        self::assertSame(0, $rez2[21]['bazaFSL']);

        // control sum: partners + rezumat2 bases; partner counts; invoices issued
        self::assertSame(2, $data['informatii']['nrCui1']);
        self::assertSame(0, $data['informatii']['nrCui2']);
        self::assertSame(3, $data['informatii']['nrFacturi']);
        self::assertSame(2 + 1501 + 300 + 200, $data['totalPlata_A']);
        self::assertSame(1, $data['header']['op_efectuate']);
        self::assertSame(0, $data['header']['sistemTVA']);
        self::assertArrayNotHasKey('tvaCol21', $data['informatii'], 'VAT per rate is only for VAT on collection');
        self::assertSame(0, $data['informatii']['solicit']);
        // the refund block is a set of 0 / 1 flags: goods sold at 21 and 11 %, none at 5 %, services bought at none
        self::assertSame([1, 1, 0, 1, 0], [$data['informatii']['BUN21'], $data['informatii']['BUN11'], $data['informatii']['BUN5'], $data['informatii']['achizitiiB21'], $data['informatii']['achizitiiS21']]);
        self::assertSame(1, $data['informatii']['valoareScutit']);
        self::assertSame(1, $data['informatii']['efectuat']);
        self::assertSame([], $data['warnings']);

        // series: tip 1 (allocated) + tip 2 (issued in the period)
        self::assertCount(2, $data['serieFacturi']);
        self::assertSame([1, 'FT', '1'], [$data['serieFacturi'][0]['tip'], $data['serieFacturi'][0]['serieI'], $data['serieFacturi'][0]['nrI']]);
        self::assertSame(2, $data['serieFacturi'][1]['tip']);

        // web summaries keep 2-decimal amounts
        self::assertSame('1800.50', $data['totals']['sales']['taxableBase']);
        self::assertSame('350.00', $data['totals']['purchases']['taxableBase']);
        self::assertSame($clientCui, $data['sales'][0]['partnerCif']);
        self::assertSame(3, $data['sales'][0]['invoiceCount']);
    }

    public function testPartnerTypesAndTheirOperationTypes(): void
    {
        $pjNoVat = (string) self::cui('3333333');
        $data = $this->populate([
            // RO company not registered for VAT: tip_partener 2 with its CUI, sale without VAT → LS
            $this->invoice('out', [['base' => '100.00', 'rate' => '0', 'cat' => 'Z']], ['cui' => $pjNoVat, 'vatPayer' => false]),
            // private person with CNP: tip_partener 2, sale with VAT → L at 21
            $this->invoice('out', [['base' => '100.00', 'rate' => '21']], ['cnp' => '1800101221144', 'vatPayer' => false, 'individual' => true]),
            // two private persons without any identifier → one row per (tip, cota) with the county code
            $this->invoice('out', [['base' => '10.00', 'rate' => '21']], ['vatPayer' => false, 'individual' => true, 'county' => 'BC', 'name' => 'Popescu A']),
            $this->invoice('out', [['base' => '20.00', 'rate' => '21']], ['vatPayer' => false, 'individual' => true, 'county' => 'BC', 'name' => 'Ionescu B']),
            // EU partner with VAT number → tip_partener 3, no address attributes
            $this->invoice('out', [['base' => '400.00', 'rate' => '21']], ['country' => 'DE', 'vatCode' => 'DE123456789', 'cui' => 'DE123456789']),
            // non-EU partner
            $this->invoice('out', [['base' => '500.00', 'rate' => '0', 'cat' => 'E']], ['country' => 'US', 'cui' => 'US-99-1234']),
            // purchase from a RO supplier not registered for VAT → N with the invoice as document
            $this->invoice('in', [['base' => '80.00', 'rate' => '0', 'cat' => 'E']], ['cui' => $pjNoVat, 'vatPayer' => false]),
        ]);

        self::assertSame(100, self::row($data, 'LS', $pjNoVat)['baza']);
        self::assertSame(2, self::row($data, 'LS', $pjNoVat)['tip_partener']);
        $cnpRow = self::row($data, 'L', '1800101221144', 21);
        self::assertSame(2, $cnpRow['tip_partener']);
        self::assertNull($cnpRow['judP']);
        $pf = self::row($data, 'L', null, 21);
        self::assertNotNull($pf, 'PF without identifier aggregate into one row');
        self::assertSame(30, $pf['baza']);
        self::assertSame(2, $pf['nrFact']);
        self::assertSame('PERSOANE FIZICE', $pf['denP']);
        self::assertSame('RO', $pf['taraP']);
        self::assertSame('04', $pf['judP']);
        $eu = self::row($data, 'L', 'DE123456789', 21);
        self::assertSame(3, $eu['tip_partener']);
        self::assertNull($eu['taraP']);
        self::assertSame(4, self::row($data, 'LS', 'US991234')['tip_partener']);
        $n = self::row($data, 'N', $pjNoVat);
        self::assertSame(80, $n['baza']);
        self::assertSame(1, $n['tip_document']);
        self::assertNull($n['tva']);

        $rez = [];
        foreach ($data['rezumat1'] as $r) {
            $rez[$r['tip_partener'] . '|' . $r['cota']] = $r;
        }
        self::assertSame(1, $rez['2|0']['document_N']);
        self::assertSame(100, $rez['2|0']['bazaLS']);
        self::assertSame(80, $rez['2|0']['bazaN']);
        self::assertSame(1, $rez['2|0']['facturiN']);
        self::assertSame(130, $rez['2|21']['bazaL']);
        self::assertSame(3, $rez['2|21']['facturiL']);
        self::assertArrayNotHasKey('facturiA', $rez['2|21']);
        self::assertSame(400, $rez['3|21']['bazaL']);
        self::assertSame(0, $rez['3|21']['facturiC']);
        self::assertSame(500, $rez['4|0']['bazaLS']);

        // nrCui2 counts rows, the others distinct partners
        self::assertSame(0, $data['informatii']['nrCui1']);
        self::assertSame(4, $data['informatii']['nrCui2']);
        self::assertSame(1, $data['informatii']['nrCui3']);
        self::assertSame(1, $data['informatii']['nrCui4']);
        self::assertSame(4 + 1 + 1 + (130 + 400), $data['totalPlata_A']);
    }

    public function testWhatIsLeftOutAndWarned(): void
    {
        $ok = (string) self::cui('4444444');
        $data = $this->populate([
            $this->invoice('out', [['base' => '100.00', 'rate' => '21']], ['cui' => $ok]),
            // to the company's own CUI: not a partner
            $this->invoice('out', [['base' => '100.00', 'rate' => '21']], ['cui' => (string) $this->company->getCif()]),
            // VAT payer with a wrong check digit
            $this->invoice('out', [['base' => '100.00', 'rate' => '21']], ['cui' => '12345678']),
            // VAT payer without CUI
            $this->invoice('out', [['base' => '100.00', 'rate' => '21']], ['cui' => null, 'name' => 'Necunoscut SRL']),
            // PF without identifier and without county
            $this->invoice('out', [['base' => '100.00', 'rate' => '21']], ['vatPayer' => false, 'individual' => true]),
            // reverse charge sale / purchase (V / C need op11)
            $this->invoice('out', [['base' => '100.00', 'rate' => '0', 'cat' => 'AE']], ['cui' => $ok]),
            $this->invoice('in', [['base' => '100.00', 'rate' => '0', 'cat' => 'AE']], ['cui' => $ok]),
            // purchase from a private person (needs op11)
            $this->invoice('in', [['base' => '100.00', 'rate' => '0']], ['cui' => '1800101221144', 'vatPayer' => false]),
            // intra-community delivery (D390) and export: only in the informative block
            $this->invoice('out', [['base' => '700.00', 'rate' => '0', 'cat' => 'K'], ['base' => '50.00', 'rate' => '0', 'cat' => 'K', 'service' => true]], ['country' => 'DE', 'vatCode' => 'DE123456789']),
            $this->invoice('out', [['base' => '900.00', 'rate' => '0', 'cat' => 'G']], ['country' => 'US', 'cui' => 'US1']),
            // intra-community acquisition: not in D394
            $this->invoice('in', [['base' => '100.00', 'rate' => '21']], ['country' => 'DE', 'cui' => 'DE5']),
            // simplified invoice
            $this->invoice('out', [['base' => '100.00', 'rate' => '21']], ['cui' => $ok, 'type' => InvoiceTypeCode::SIMPLIFIED->value]),
            // a rate the form does not have
            $this->invoice('out', [['base' => '100.00', 'rate' => '7']], ['cui' => $ok]),
        ]);

        self::assertCount(1, $data['partners']);
        self::assertSame(100, $data['partners'][0]['baza']);
        $codes = array_column($data['warnings'], 'code');
        foreach (['PARTNER_WITHOUT_ID', 'PARTNER_INVALID_CUI', 'PARTNER_WITHOUT_COUNTY', 'REVERSE_CHARGE_SALES_SKIPPED', 'REVERSE_CHARGE_PURCHASES_SKIPPED', 'INDIVIDUAL_SUPPLIER_SKIPPED', 'UNSUPPORTED_RATE'] as $code) {
            self::assertContains($code, $codes);
        }
        self::assertSame(1, $data['excluded']['own_cui']);
        self::assertSame(1, $data['excluded']['simplified']);
        self::assertSame(1, $data['excluded']['foreign_purchase']);
        self::assertSame([1, 1, 1], [$data['informatii']['LIntra'], $data['informatii']['PrestIntra'], $data['informatii']['Export']]);
        self::assertSame(1, $data['excluded']['export']);
        self::assertSame(2, $data['excluded']['intra_community']);
        self::assertSame(1, $data['informatii']['nrCui1']);
        // every issued invoice counts in nrFacturi, even the ones whose partner could not be declared
        self::assertSame(10, $data['informatii']['nrFacturi']);
    }

    public function testVatOnCollectionQuarterAndForeignCurrency(): void
    {
        $this->company->setVatOnCollection(true);
        $cui = (string) self::cui('5555555');
        $series = new DocumentSeries();
        $series->setCompany($this->company);
        $series->setPrefix('FT');
        $series->setType('invoice');
        $series->setCurrentNumber(120);
        $data = $this->populate([
            $this->invoice('out', [['base' => '100.00', 'rate' => '21']], ['cui' => $cui, 'currency' => 'EUR', 'exchangeRate' => '5.0000', 'number' => 'FT0100', 'date' => '2026-07-05']),
            $this->invoice('in', [['base' => '40.00', 'rate' => '11']], ['cui' => $cui, 'date' => '2026-09-05']),
        ], 2026, 9, 'quarterly', [$series]);

        self::assertSame('T', $data['header']['tip_D394']);
        self::assertSame(9, $data['header']['luna']);
        self::assertSame(1, $data['header']['sistemTVA']);
        self::assertSame('2026-07-01', $data['period']['from']);
        self::assertSame(500, self::row($data, 'L', $cui, 21)['baza']);
        self::assertSame(105, self::row($data, 'L', $cui, 21)['tva']);
        self::assertSame(105, $data['informatii']['tvaCol21']);
        self::assertSame(0, $data['informatii']['tvaCol11']);
        self::assertSame(4, $data['informatii']['tvaDed11']);
        self::assertSame(0, $data['informatii']['tvaDed24']);
        self::assertContains('VAT_ON_COLLECTION_BY_INVOICE', array_column($data['warnings'], 'code'));
        self::assertSame('120', $data['serieFacturi'][0]['nrF'], 'the allocated range ends at the series counter');
        self::assertSame(['100', '100'], [$data['serieFacturi'][1]['nrI'], $data['serieFacturi'][1]['nrF']]);
    }

    public function testEmptyPeriodHasNoOperationsAndNoSeries(): void
    {
        $this->company->setCaenCode(null);
        $this->company->setRepresentative(null);
        $data = $this->populate([]);
        self::assertSame(0, $data['header']['op_efectuate']);
        self::assertSame([], $data['partners']);
        self::assertSame([], $data['rezumat1']);
        self::assertSame([], $data['serieFacturi']);
        self::assertSame(0, $data['informatii']['nrFacturi']);
        self::assertSame(0, $data['totalPlata_A']);
        self::assertSame(['MISSING_CAEN', 'MISSING_REPRESENTATIVE'], array_column($data['warnings'], 'code'));
    }
}
