<?php

namespace App\Tests\Unit;

use App\Entity\BankAccount;
use App\Entity\Client;
use App\Entity\Company;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\Payment;
use App\Entity\Product;
use App\Entity\Supplier;
use App\Entity\TaxDeclaration;
use App\Enum\DeclarationType;
use App\Enum\DocumentType;
use App\Enum\InvoiceDirection;
use App\Enum\InvoiceTypeCode;
use App\Repository\BankAccountRepository;
use App\Repository\InvoiceRepository;
use App\Repository\PaymentRepository;
use App\Service\Declaration\Populator\D406Populator;
use App\Service\Declaration\Saft\SaftPartnerId;
use App\Service\Declaration\Saft\SaftTaxCodes;
use App\Service\Declaration\XmlGenerator\D406XmlGenerator;
use PHPUnit\Framework\TestCase;

class D406PopulatorTest extends TestCase
{
    private Company $company;

    protected function setUp(): void
    {
        $this->company = new Company();
        $this->company->setName('Firma Test SRL');
        $this->company->setCif(self::cui('3138536'));
        $this->company->setVatPayer(true);
        $this->company->setAddress('Str. Exemplu 1');
        $this->company->setCity('Bacau');
        $this->company->setState('BC');
        $this->company->setCountry('RO');
        $this->company->setPhone('0234000000');
        $this->company->setEmail('office@example.com');
        $this->company->setRepresentative('Popescu Ion');
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

    /** A CNP with a valid check digit (placeholder). */
    private static function cnp(string $body12): string
    {
        $weights = [2, 7, 9, 1, 4, 6, 3, 5, 8, 2, 7, 9];
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += ((int) $body12[$i]) * $weights[$i];
        }
        $check = $sum % 11;

        return $body12 . ($check === 10 ? 1 : $check);
    }

    /**
     * @param list<array{0: string, 1: string, 2: string, 3?: ?Product}> $lines [net, rate, category, product]
     */
    private function invoice(string $direction, array $lines, array $opts = []): Invoice
    {
        static $n = 0;
        $n++;
        $inv = new Invoice();
        $inv->setCompany($this->company);
        $inv->setDirection($direction === 'out' ? InvoiceDirection::OUTGOING : InvoiceDirection::INCOMING);
        $inv->setDocumentType($opts['creditNote'] ?? false ? DocumentType::CREDIT_NOTE : DocumentType::INVOICE);
        $inv->setIssueDate(new \DateTime($opts['date'] ?? '2026-08-10'));
        $inv->setCurrency($opts['currency'] ?? 'RON');
        $inv->setNumber($opts['number'] ?? sprintf('FT%04d', $n));
        if (isset($opts['exchangeRate'])) {
            $inv->setExchangeRate($opts['exchangeRate']);
        }
        if (isset($opts['type'])) {
            $inv->setInvoiceTypeCode($opts['type']);
        }
        if ($opts['vatOnCollection'] ?? false) {
            $inv->setTvaLaIncasare(true);
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
            $client->setCity($opts['city'] ?? 'Bacau');
            if (isset($opts['clientCode'])) {
                $client->setClientCode($opts['clientCode']);
            }
            $inv->setClient($client);
            $inv->setReceiverCif($opts['cui'] ?? null);
            $inv->setReceiverName($client->getName());
        } else {
            $supplier = new Supplier();
            $supplier->setCompany($this->company);
            $supplier->setName($opts['name'] ?? 'Furnizor SRL');
            $supplier->setCountry($opts['country'] ?? 'RO');
            $supplier->setCif($opts['cui'] ?? null);
            $supplier->setVatCode($opts['vatCode'] ?? null);
            $supplier->setIsVatPayer($opts['vatPayer'] ?? true);
            $supplier->setCity($opts['city'] ?? 'Cluj-Napoca');
            $inv->setSupplier($supplier);
            $inv->setSenderCif($opts['cui'] ?? null);
            $inv->setSenderName($supplier->getName());
        }
        $subtotal = '0.00';
        $vatTotal = '0.00';
        foreach ($lines as $i => $line) {
            [$net, $rate, $category] = $line;
            $product = $line[3] ?? null;
            $vat = bcdiv(bcmul($net, $rate, 4), '100', 2);
            $il = (new InvoiceLine())
                ->setPosition($i + 1)
                ->setDescription('Linie ' . ($i + 1))
                ->setQuantity('2.00')
                ->setUnitOfMeasure($opts['unit'] ?? 'buc')
                ->setUnitPrice(bcdiv($net, '2', 2))
                ->setVatRate($rate)
                ->setVatCategoryCode($category)
                ->setVatAmount($vat)
                ->setLineTotal($net)
                ->setDiscount('0.00')
                ->setDiscountPercent('0.00');
            if ($product !== null) {
                $il->setProduct($product);
            }
            $inv->addLine($il);
            $subtotal = bcadd($subtotal, $net, 2);
            $vatTotal = bcadd($vatTotal, $vat, 2);
        }
        $inv->setSubtotal($subtotal)->setVatTotal($vatTotal)->setTotal(bcadd($subtotal, $vatTotal, 2))->setDiscount('0.00');

        return $inv;
    }

    private function product(string $name, bool $service, ?string $nc = null, string $unit = 'buc'): Product
    {
        $product = new Product();
        $product->setCompany($this->company);
        $product->setName($name);
        $product->setCode(strtoupper(substr(preg_replace('/[^a-z]/i', '', $name), 0, 6)));
        $product->setIsService($service);
        $product->setUnitOfMeasure($unit);
        $product->setNcCode($nc);

        return $product;
    }

    private function payment(Invoice $invoice, string $amount, string $method, string $date = '2026-08-20'): Payment
    {
        $payment = new Payment();
        $payment->setCompany($this->company);
        $payment->setInvoice($invoice);
        $payment->setAmount($amount);
        $payment->setCurrency($invoice->getCurrency() ?: 'RON');
        $payment->setPaymentDate(new \DateTime($date));
        $payment->setPaymentMethod($method);
        $payment->setReference('OP 12');

        return $payment;
    }

    /**
     * @param Invoice[] $invoices
     * @param Payment[] $payments
     * @param BankAccount[] $bankAccounts
     */
    private function populate(array $invoices, array $payments = [], ?array $bankAccounts = null, int $year = 2026, int $month = 8, string $period = 'monthly'): array
    {
        $invoiceRepo = $this->createMock(InvoiceRepository::class);
        $invoiceRepo->method('findForVatReturn')->willReturn($invoices);
        $paymentRepo = $this->createMock(PaymentRepository::class);
        $paymentRepo->method('findByCompanyAndDirectionFiltered')->willReturnCallback(
            static fn (Company $c, InvoiceDirection $direction) => array_values(array_filter($payments, static fn (Payment $p) => $p->getInvoice()?->getDirection() === $direction))
        );
        if ($bankAccounts === null) {
            $account = (new BankAccount())->setCompany($this->company)->setIban('RO49AAAA1B31007593840000')->setBankName('Banca Test')->setIsDefault(true);
            $bankAccounts = [$account];
        }
        $bankRepo = $this->createMock(BankAccountRepository::class);
        $bankRepo->method('findByCompany')->willReturn($bankAccounts);

        return (new D406Populator($invoiceRepo, $paymentRepo, $bankRepo, null, '2.7.46'))->populate($this->company, $year, $month, $period);
    }

    /** @return array<string, array<string, string>> lines keyed by "account|side" → amount */
    private function ledgerLines(array $data, string $journal): array
    {
        $out = [];
        foreach ($data['journals'] as $j) {
            if ($j['key'] !== $journal) {
                continue;
            }
            foreach ($j['transactions'] as $t) {
                foreach ($t['lines'] as $l) {
                    $key = $l['accountId'] . '|' . $l['side'];
                    $out[$key] = bcadd($out[$key] ?? '0.00', $l['amount'], 2);
                }
            }
        }

        return $out;
    }

    public function testSalesInvoiceIsBookedOnCustomersRevenueAndCollectedVat(): void
    {
        $cui = (string) self::cui('7654321');
        $service = $this->product('Consultanta', true);
        $goods = $this->product('Marfa', false, '84713000', 'kg');
        $data = $this->populate([
            $this->invoice('out', [['1000.00', '21.00', 'S', $service], ['200.00', '21.00', 'S', $goods]], ['cui' => $cui, 'number' => 'FV0001']),
        ]);

        self::assertSame('D406', $data['form']);
        self::assertSame('L', $data['period']['type']);
        self::assertSame(1, $data['counts']['salesInvoices']);
        self::assertSame(0, $data['counts']['purchaseInvoices']);

        $lines = $this->ledgerLines($data, 'sales');
        self::assertSame('1452.00', $lines['4111|D'], 'the customer is debited with the gross total');
        self::assertSame('1000.00', $lines['707|C'], 'a line whose product is a service goes to 707');
        self::assertSame('200.00', $lines['7015|C'], 'goods go to 7015');
        self::assertSame('252.00', $lines['4427|C'], 'collected VAT per rate');
        self::assertSame($data['totals']['ledger']['debit'], $data['totals']['ledger']['credit'], 'every transaction balances');

        $invoice = $data['salesInvoices'][0];
        self::assertSame('00' . $cui, $invoice['partnerId']);
        self::assertSame('380', $invoice['type']);
        self::assertSame('C', $invoice['lines'][0]['side']);
        self::assertSame('310344', $invoice['lines'][0]['taxCode'], '21 % standard sales code');
        self::assertSame('300', $invoice['lines'][0]['taxType']);
        self::assertSame('210.00', $invoice['lines'][0]['taxAmount']);
        self::assertSame('1200.00', $invoice['netTotal']);
        self::assertSame('1452.00', $invoice['grossTotal']);
        self::assertSame('H87', $invoice['lines'][0]['uom'], 'the line carries the unit of the invoice line');

        $customer = $data['customers'][0];
        self::assertSame('00' . $cui, $customer['id']);
        self::assertSame('4111', $customer['accountId']);
        self::assertSame('1452.00', $customer['closingDebit'], 'nothing paid yet');
        self::assertSame('0.00', $customer['openingDebit']);

        $products = array_column($data['products'], null, 'code');
        self::assertSame('02', $products['CONSUL']['goodsServicesId']);
        self::assertSame('01', $products['MARFA']['goodsServicesId']);
        self::assertSame('84713000', $products['MARFA']['commodityCode']);
        self::assertSame('KGM', $products['MARFA']['uom'], 'the product carries its own unit');
        self::assertSame('H87', $products['CONSUL']['uom']);
        self::assertSame('0', $products['CONSUL']['commodityCode'], 'no NC code → 0');

        $codes = array_column($data['taxCodes'], 'code');
        self::assertContains('310344', $codes);
        self::assertContains(SaftTaxCodes::TAX_CODE_NONE, $codes, 'the no-tax pair of the partner lines is in the TaxTable');
        $uoms = array_column($data['uoms'], 'code');
        self::assertContains('H87', $uoms);
        self::assertContains('KGM', $uoms);
    }

    public function testPurchaseInvoiceIsBookedOnExpensesDeductibleVatAndSuppliers(): void
    {
        $cui = (string) self::cui('1357911');
        $service = $this->product('Chirie', true);
        $data = $this->populate([
            $this->invoice('in', [['300.00', '21.00', 'S', $service], ['100.00', '9.00', 'S']], ['cui' => $cui, 'number' => 'FZ77']),
        ]);

        $lines = $this->ledgerLines($data, 'purchases');
        self::assertSame('472.00', $lines['401|C']);
        self::assertSame('300.00', $lines['628|D'], 'services go to 628');
        self::assertSame('100.00', $lines['604|D'], 'lines without a product are goods');
        self::assertSame('72.00', $lines['4426|D']);

        $invoice = $data['purchaseInvoices'][0];
        self::assertSame('00' . $cui, $invoice['partnerId']);
        self::assertSame('D', $invoice['lines'][0]['side']);
        self::assertSame('301104', $invoice['lines'][0]['taxCode']);
        self::assertSame('301102', $invoice['lines'][1]['taxCode']);
        self::assertSame('472.00', $data['suppliers'][0]['closingCredit']);
        self::assertSame('0.00', $data['suppliers'][0]['openingCredit']);
    }

    public function testPaymentsGoThroughBankOrCashAgainstThePartnerAccount(): void
    {
        $cui = (string) self::cui('7654321');
        $sale = $this->invoice('out', [['1000.00', '21.00', 'S']], ['cui' => $cui, 'number' => 'FV0002']);
        $purchase = $this->invoice('in', [['500.00', '21.00', 'S']], ['cui' => (string) self::cui('1357911'), 'number' => 'FZ1']);
        $data = $this->populate([$sale, $purchase], [
            $this->payment($sale, '1210.00', 'bank_transfer'),
            $this->payment($purchase, '605.00', 'cash'),
        ]);

        $bank = $this->ledgerLines($data, 'bank');
        self::assertSame('1210.00', $bank['5121|D']);
        self::assertSame('1210.00', $bank['4111|C']);
        $cash = $this->ledgerLines($data, 'cash');
        self::assertSame('605.00', $cash['401|D']);
        self::assertSame('605.00', $cash['5311|C']);

        self::assertCount(2, $data['payments']);
        $byDirection = array_column($data['payments'], null, 'direction');
        self::assertSame('03', $byDirection['in']['method']);
        self::assertSame('42', $byDirection['in']['mechanism']);
        self::assertSame('01', $byDirection['out']['method']);
        self::assertSame('10', $byDirection['out']['mechanism']);
        self::assertSame('C', $byDirection['in']['lines'][0]['side'], 'a receipt credits the customer');
        self::assertSame('D', $byDirection['out']['lines'][0]['side'], 'a payment debits the supplier');
        self::assertSame(SaftTaxCodes::TAX_CODE_NONE, $byDirection['in']['lines'][0]['taxCode']);

        self::assertSame('0.00', $data['customers'][0]['closingDebit'], 'fully paid');
        self::assertSame('1210.00', $data['totals']['payments']['received']);
        self::assertSame('605.00', $data['totals']['payments']['paid']);
        $accounts = array_column($data['accounts'], null, 'id');
        self::assertSame('1210.00', $accounts['5121']['closingDebit']);
        self::assertSame('Bifunctional', $accounts['5121']['type']);
        self::assertSame('605.00', $accounts['5311']['closingCredit'], 'only the movements of the period are known');
    }

    public function testCreditNoteSwapsSidesInsteadOfWritingNegativeAmounts(): void
    {
        $cui = (string) self::cui('7654321');
        $data = $this->populate([
            $this->invoice('out', [['-100.00', '21.00', 'S', $this->product('Consultanta', true)]], ['cui' => $cui, 'number' => 'ST1', 'creditNote' => true]),
        ]);

        $invoice = $data['salesInvoices'][0];
        self::assertSame('381', $invoice['type']);
        self::assertSame('D', $invoice['lines'][0]['side']);
        self::assertSame('100.00', $invoice['lines'][0]['amount']);
        self::assertSame('-100.00', $invoice['netTotal']);
        $lines = $this->ledgerLines($data, 'sales');
        self::assertSame('121.00', $lines['4111|C']);
        self::assertSame('100.00', $lines['707|D']);
        self::assertSame('21.00', $lines['4427|D']);
        self::assertSame('121.00', $data['customers'][0]['closingCredit']);
    }

    public function testPartnersAreIdentifiedTheWayAnafRequires(): void
    {
        $cnp = self::cnp('198012312345');
        $data = $this->populate([
            $this->invoice('out', [['10.00', '21.00', 'S']], ['individual' => true, 'vatPayer' => false, 'cnp' => $cnp, 'name' => 'Persoana Cu CNP']),
            $this->invoice('out', [['20.00', '21.00', 'S']], ['individual' => true, 'vatPayer' => false, 'clientCode' => 'CL-15', 'name' => 'Persoana Fara CNP']),
            $this->invoice('out', [['30.00', '0.00', 'K']], ['country' => 'DE', 'vatCode' => 'DE123456789', 'name' => 'Kunde GmbH']),
            $this->invoice('out', [['40.00', '0.00', 'G']], ['country' => 'US', 'cui' => '12-3456789', 'name' => 'Buyer Inc']),
            $this->invoice('out', [['50.00', '21.00', 'S']], ['name' => 'Fara Identificator', 'cui' => null]),
            $this->invoice('out', [['60.00', '21.00', 'S']], ['name' => 'CUI Gresit', 'cui' => '12345678']),
        ]);

        $ids = array_column($data['customers'], 'id', 'name');
        self::assertSame('03' . $cnp, $ids['Persoana Cu CNP']);
        self::assertSame('04CL15', $ids['Persoana Fara CNP'], 'individuals without CNP carry a company-assigned code, letters and digits only');
        self::assertSame('01DE123456789', $ids['Kunde GmbH'], 'EU: 01 + country + VAT number');
        self::assertSame('02US123456789', $ids['Buyer Inc'], 'non-EU: 02 + country + tax code');
        self::assertArrayNotHasKey('Fara Identificator', $ids);
        self::assertArrayNotHasKey('CUI Gresit', $ids);
        self::assertSame(2, $data['counts']['excludedInvoices']);

        $codes = array_column($data['warnings'], 'code');
        self::assertContains('PARTNER_WITHOUT_ID', $codes);
        self::assertContains('PARTNER_INVALID_ID', $codes);

        $byPartner = array_column($data['salesInvoices'], null, 'partnerId');
        self::assertSame('310301', $byPartner['01DE123456789']['lines'][0]['taxCode'], 'intra-community delivery of goods');
        self::assertSame('310313', $byPartner['02US123456789']['lines'][0]['taxCode'], 'export');
        self::assertSame(SaftPartnerId::UNIDENTIFIED_POS_CUSTOMER, '080000000000000');
    }

    public function testHeaderCarriesTheCompanyAndTheGapWarnings(): void
    {
        $data = $this->populate([$this->invoice('out', [['10.00', '21.00', 'S']], ['cui' => (string) self::cui('7654321')])]);

        $header = $data['header'];
        self::assertSame('RO' . self::cui('3138536'), $header['registrationNumber']);
        self::assertSame('RO-BC', $header['region']);
        self::assertSame('Bacau', $header['city']);
        self::assertSame('100010', $header['taxRegistration']['type']);
        self::assertSame((string) self::cui('3138536'), $header['taxRegistration']['taxNumber']);
        self::assertSame('RO49AAAA1B31007593840000', $header['bankAccounts'][0]['iban']);
        self::assertSame(['firstName' => 'Ion', 'lastName' => 'Popescu', 'phone' => '0234000000', 'email' => 'office@example.com'], $header['contact']);
        self::assertSame('2.7.46', $header['softwareVersion']);
        self::assertSame('L', $header['headerComment']);
        self::assertSame(8, $header['selection']['periodStart']);
        self::assertSame(8, $header['selection']['periodEnd']);
        self::assertSame('A', $header['taxAccountingBasis']);

        $codes = array_column($data['warnings'], 'code');
        self::assertContains('NO_OPENING_BALANCES', $codes);
        self::assertContains('LEDGER_FROM_DOCUMENTS_ONLY', $codes);
        self::assertNotContains('MISSING_BANK_ACCOUNT', $codes);

        $quarter = $this->populate([], [], null, 2026, 8, 'quarterly');
        self::assertSame('T', $quarter['period']['type']);
        self::assertSame(7, $quarter['header']['selection']['periodStart']);
        self::assertSame(9, $quarter['header']['selection']['periodEnd']);
        self::assertSame('2026-07-01', $quarter['period']['from']);
        self::assertContains('NO_OPERATIONS', array_column($quarter['warnings'], 'code'));

        $noBank = $this->populate([], [], []);
        self::assertContains('MISSING_BANK_ACCOUNT', array_column($noBank['warnings'], 'code'));
    }

    public function testCompanyNotRegisteredForVatKeepsTheVatInTheExpense(): void
    {
        $this->company->setVatPayer(false);
        $data = $this->populate([
            $this->invoice('in', [['100.00', '21.00', 'S']], ['cui' => (string) self::cui('1357911')]),
            $this->invoice('out', [['200.00', '0.00', 'E']], ['cui' => (string) self::cui('7654321')]),
        ]);

        $lines = $this->ledgerLines($data, 'purchases');
        self::assertSame('121.00', $lines['604|D'], 'gross in the expense');
        self::assertArrayNotHasKey('4426|D', $lines);
        self::assertSame('351104', $data['purchaseInvoices'][0]['lines'][0]['taxCode'], 'non-deductible purchase code');
        self::assertSame('121.00', $data['purchaseInvoices'][0]['grossTotal']);
        self::assertSame('310326', $data['salesInvoices'][0]['lines'][0]['taxCode'], 'sales of a small enterprise are exempt without deduction');
        self::assertSame('100020', $data['header']['taxRegistration']['type']);
        self::assertSame((string) self::cui('3138536'), $data['header']['registrationNumber'], 'no RO prefix without VAT registration');
    }

    public function testSpecialSalesAndPurchaseCases(): void
    {
        $this->company->setVatOnCollection(true);
        $data = $this->populate([
            $this->invoice('out', [['100.00', '21.00', 'S']], ['cui' => (string) self::cui('7654321'), 'number' => 'A']),
            $this->invoice('out', [['100.00', '0.00', 'AE']], ['cui' => (string) self::cui('7654321'), 'number' => 'B']),
            $this->invoice('out', [['100.00', '0.00', 'E']], ['cui' => (string) self::cui('7654321'), 'number' => 'C']),
            $this->invoice('in', [['100.00', '0.00', 'AE']], ['cui' => (string) self::cui('1357911'), 'number' => 'D']),
            $this->invoice('in', [['100.00', '0.00', 'S']], ['country' => 'DE', 'vatCode' => 'DE999999999', 'number' => 'E']),
            $this->invoice('in', [['100.00', '21.00', 'S']], ['cui' => (string) self::cui('1357911'), 'number' => 'F', 'vatOnCollection' => true]),
        ]);

        $sales = array_column($data['salesInvoices'], null, 'invoiceNo');
        self::assertSame('310350', $sales['A']['lines'][0]['taxCode'], 'the company applies VAT on collection');
        self::assertSame('310312', $sales['B']['lines'][0]['taxCode'], 'reverse charge');
        self::assertSame('310326', $sales['C']['lines'][0]['taxCode'], 'exempt without deduction');
        $purchases = array_column($data['purchaseInvoices'], null, 'invoiceNo');
        self::assertSame('300906', $purchases['D']['lines'][0]['taxCode'], 'domestic reverse charge at the standard rate');
        self::assertSame(21, $purchases['D']['lines'][0]['taxPercentage']);
        self::assertSame('0.00', $purchases['D']['lines'][0]['taxAmount'], 'as invoiced');
        self::assertSame('300104', $purchases['E']['lines'][0]['taxCode'], 'intra-community acquisition of goods');
        self::assertSame('301305', $purchases['F']['lines'][0]['taxCode'], 'supplier applies VAT on collection');
        self::assertContains('REVERSE_CHARGE_VAT_NOT_BOOKED', array_column($data['warnings'], 'code'));
    }

    public function testForeignCurrencyInvoicesCarryTheCurrencyAmountAndTheRate(): void
    {
        $data = $this->populate([
            $this->invoice('out', [['100.00', '21.00', 'S']], ['cui' => (string) self::cui('7654321'), 'currency' => 'EUR', 'exchangeRate' => '4.9750']),
        ]);
        $line = $data['salesInvoices'][0]['lines'][0];
        self::assertSame('497.50', $line['amount']);
        self::assertSame('100.00', $line['currencyAmount']);
        self::assertSame('EUR', $line['currency']);
        self::assertSame('4.9750', $line['exchangeRate']);
        self::assertSame('104.48', $line['taxAmount']);
        self::assertSame('21.00', $line['taxAmountCurrency']);
        $lines = $this->ledgerLines($data, 'sales');
        self::assertSame('601.98', $lines['4111|D']);
    }

    public function testXmlFollowsTheSchemaOrderAndTheInstanceNamespace(): void
    {
        $cui = (string) self::cui('7654321');
        $sale = $this->invoice('out', [['1000.00', '21.00', 'S', $this->product('Consultanta', true)]], ['cui' => $cui, 'number' => 'FV0009']);
        $data = $this->populate([$sale, $this->invoice('in', [['300.00', '21.00', 'S']], ['cui' => (string) self::cui('1357911')])], [$this->payment($sale, '1210.00', 'card')]);

        $declaration = (new TaxDeclaration())->setCompany($this->company)->setType(DeclarationType::D406)->setYear(2026)->setMonth(8)->setPeriodType('monthly')->setData($data);
        $xml = (new D406XmlGenerator())->generate($declaration);

        $dom = new \DOMDocument();
        self::assertTrue($dom->loadXML($xml));
        self::assertSame('AuditFile', $dom->documentElement->localName);
        self::assertSame('mfp:anaf:dgti:d406:declaratie:v1', $dom->documentElement->namespaceURI);
        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('s', 'mfp:anaf:dgti:d406:declaratie:v1');
        $names = [];
        foreach ($xp->query('/s:AuditFile/*') as $node) {
            $names[] = $node->localName;
        }
        self::assertSame(['Header', 'MasterFiles', 'GeneralLedgerEntries', 'SourceDocuments'], $names);
        $header = [];
        foreach ($xp->query('/s:AuditFile/s:Header/*') as $node) {
            $header[] = $node->localName;
        }
        self::assertSame(['AuditFileVersion', 'AuditFileCountry', 'AuditFileDateCreated', 'SoftwareCompanyName', 'SoftwareID', 'SoftwareVersion', 'Company', 'DefaultCurrencyCode', 'SelectionCriteria', 'HeaderComment', 'SegmentIndex', 'TotalSegmentsInsequence', 'TaxAccountingBasis'], $header);
        $master = [];
        foreach ($xp->query('/s:AuditFile/s:MasterFiles/*') as $node) {
            $master[] = $node->localName;
        }
        self::assertSame(['GeneralLedgerAccounts', 'Customers', 'Suppliers', 'TaxTable', 'UOMTable', 'AnalysisTypeTable', 'MovementTypeTable', 'Products', 'Owners', 'Assets'], $master);
        self::assertSame('RO49AAAA1B31007593840000', $xp->evaluate('string(/s:AuditFile/s:Header/s:Company/s:BankAccount/s:IBANNumber)'));
        self::assertSame('00' . $cui, $xp->evaluate('string(/s:AuditFile/s:MasterFiles/s:Customers/s:Customer/s:CustomerID)'));
        self::assertSame('00' . $cui, $xp->evaluate('string(/s:AuditFile/s:MasterFiles/s:Customers/s:Customer/s:CompanyStructure/s:RegistrationNumber)'));
        self::assertSame('3', $xp->evaluate('string(/s:AuditFile/s:GeneralLedgerEntries/s:NumberOfEntries)'));
        self::assertSame($xp->evaluate('string(/s:AuditFile/s:GeneralLedgerEntries/s:TotalDebit)'), $xp->evaluate('string(/s:AuditFile/s:GeneralLedgerEntries/s:TotalCredit)'));
        self::assertSame('1', $xp->evaluate('string(/s:AuditFile/s:SourceDocuments/s:SalesInvoices/s:NumberOfEntries)'));
        self::assertSame('1210.00', $xp->evaluate('string(/s:AuditFile/s:SourceDocuments/s:SalesInvoices/s:Invoice/s:InvoiceDocumentTotals/s:GrossTotal)'));
        self::assertSame('C', $xp->evaluate('string(/s:AuditFile/s:SourceDocuments/s:SalesInvoices/s:Invoice/s:InvoiceLine/s:DebitCreditIndicator)'));
        self::assertSame('310344', $xp->evaluate('string(/s:AuditFile/s:SourceDocuments/s:SalesInvoices/s:Invoice/s:InvoiceLine/s:TaxInformation/s:TaxCode)'));
        self::assertSame('03', $xp->evaluate('string(/s:AuditFile/s:SourceDocuments/s:Payments/s:Payment/s:PaymentMethod)'));
        self::assertSame('48', $xp->evaluate('string(/s:AuditFile/s:SourceDocuments/s:Payments/s:Payment/s:PaymentSettlement/s:PaymentMechanism)'));
        self::assertSame('000000', $xp->evaluate('string(/s:AuditFile/s:SourceDocuments/s:Payments/s:Payment/s:PaymentLine/s:TaxInformation/s:TaxCode)'));
        self::assertSame(1, $xp->query('//s:MovementOfGoods')->length);
        self::assertSame(0, $xp->query('//s:MovementOfGoods/*')->length, 'stock is reported in the on-request file, so the section stays empty here');
        self::assertSame(0, $xp->query('//s:ExchangeRate')->length, 'RON documents carry no exchange rate');
        self::assertSame(0, $xp->query('//s:AssetTransactions')->length, 'the optional asset section is not written');
    }
}
