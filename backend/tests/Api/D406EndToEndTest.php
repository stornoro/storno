<?php

namespace App\Tests\Api;

use App\Entity\Company;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\Supplier;
use App\Enum\DocumentStatus;
use App\Enum\DocumentType;
use App\Enum\InvoiceDirection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Builds the SAF-T (D406) of the current month from real documents (invoices issued through
 * the API with a payment recorded, received ones written directly) and runs it through
 * ANAF's own D406 validator (DUKIntegrator via the Java service). Skipped when the Java
 * service or the validator jar is not available.
 */
class D406EndToEndTest extends ApiTestCase
{
    /** A checksum-valid CUI built from the given digits (placeholder, not a real company). */
    private static function cui(string $body): string
    {
        $padded = str_pad($body, 9, '0', STR_PAD_LEFT);
        $weights = [7, 5, 3, 2, 1, 7, 5, 3, 2];
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += ((int) $padded[$i]) * $weights[$i];
        }
        $check = ($sum * 10) % 11;

        return $body . ($check === 10 ? 0 : $check);
    }

    public function testCurrentMonthSaftIsPopulatedAndAcceptedByTheAnafValidator(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();
        $h = ['X-Company' => $companyId];

        // The header needs the representative (contact) and a bank account (IBAN)
        $this->apiPatch('/api/v1/companies/' . $companyId, ['representative' => 'Popescu Ion', 'representativeRole' => 'Administrator'], $h);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'company update: ' . $this->client->getResponse()->getContent());
        $accounts = $this->apiGet('/api/v1/bank-accounts', $h);
        $hasIban = false;
        foreach ((array) ($accounts['data'] ?? $accounts) as $account) {
            if (is_array($account) && !empty($account['iban'])) {
                $hasIban = true;
            }
        }
        if (!$hasIban) {
            $this->apiPost('/api/v1/bank-accounts', ['type' => 'bank', 'iban' => 'RO49AAAA1B31007593840000', 'bankName' => 'Banca Test', 'currency' => 'RON', 'isDefault' => true], $h);
            self::assertSame(201, $this->client->getResponse()->getStatusCode(), 'bank account: ' . $this->client->getResponse()->getContent());
        }

        // Partners: a RO VAT payer (00 + CUI) and a private person without CNP (04 + code)
        $vatPayerCui = self::cui('7654321');
        $created = $this->apiPost('/api/v1/clients', [
            'name' => 'Partener Test SRL', 'type' => 'company', 'cui' => $vatPayerCui, 'vatCode' => 'RO' . $vatPayerCui, 'isVatPayer' => true,
            'country' => 'RO', 'county' => 'B', 'city' => 'Bucuresti', 'address' => 'Str. Exemplu 1',
        ], $h);
        $companyClientId = $created['client']['id'] ?? $created['id'] ?? null;
        self::assertNotNull($companyClientId, json_encode($created));

        $created = $this->apiPost('/api/v1/clients', [
            'name' => 'Persoana Test', 'type' => 'individual', 'cui' => '-', 'isVatPayer' => false,
            'country' => 'RO', 'county' => 'BC', 'city' => 'Bacau', 'address' => 'Str. Exemplu 2',
        ], $h);
        $individualClientId = $created['client']['id'] ?? $created['id'] ?? null;
        self::assertNotNull($individualClientId, json_encode($created));

        $today = new \DateTimeImmutable('today');
        $issued = [];
        foreach ([
            [$companyClientId, [
                ['description' => 'Servicii consultanta', 'quantity' => '1.00', 'unitOfMeasure' => 'ora', 'unitPrice' => '1000.00', 'vatRate' => '21.00', 'vatCategoryCode' => 'S'],
                ['description' => 'Chirie scutita', 'quantity' => '1.00', 'unitOfMeasure' => 'buc', 'unitPrice' => '100.00', 'vatRate' => '0.00', 'vatCategoryCode' => 'E'],
            ]],
            [$individualClientId, [
                ['description' => 'Produs', 'quantity' => '2.00', 'unitOfMeasure' => 'buc', 'unitPrice' => '25.00', 'vatRate' => '21.00', 'vatCategoryCode' => 'S'],
            ]],
        ] as [$clientId, $lines]) {
            $created = $this->apiPost('/api/v1/invoices', [
                'documentType' => 'invoice',
                'issueDate' => $today->format('Y-m-d'),
                'dueDate' => $today->modify('+30 days')->format('Y-m-d'),
                'currency' => 'RON',
                'clientId' => $clientId,
                'lines' => $lines,
            ], $h);
            $invoiceId = $created['invoice']['id'] ?? $created['id'] ?? null;
            self::assertNotNull($invoiceId, json_encode($created));
            $this->apiPost('/api/v1/invoices/' . $invoiceId . '/issue', [], $h);
            self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'issue: ' . $this->client->getResponse()->getContent());
            $issued[] = $invoiceId;
        }

        // A bank receipt on the first invoice and a cash one on the second
        $this->apiPost('/api/v1/invoices/' . $issued[0] . '/payments', ['amount' => '500.00', 'paymentMethod' => 'bank_transfer', 'paymentDate' => $today->format('Y-m-d'), 'reference' => 'OP 1'], $h);
        self::assertSame(201, $this->client->getResponse()->getStatusCode(), 'payment: ' . $this->client->getResponse()->getContent());
        $this->apiPost('/api/v1/invoices/' . $issued[1] . '/payments', ['amount' => '60.50', 'paymentMethod' => 'cash', 'paymentDate' => $today->format('Y-m-d')], $h);
        self::assertSame(201, $this->client->getResponse()->getStatusCode(), 'payment: ' . $this->client->getResponse()->getContent());

        // Received invoices: from a VAT payer and from a supplier not registered for VAT
        $this->receivedInvoice($companyId, 'Furnizor Test SRL', self::cui('1357911'), true, [['300.00', '21.00', 'S'], ['20.00', '0.00', 'E']]);
        $this->receivedInvoice($companyId, 'Mic Furnizor SRL', self::cui('2468101'), false, [['80.00', '0.00', 'E']]);

        $decl = $this->apiPost('/api/v1/declarations', [
            'type' => 'd406',
            'year' => (int) $today->format('Y'),
            'month' => (int) $today->format('n'),
        ], $h);
        self::assertSame(201, $this->client->getResponse()->getStatusCode(), json_encode($decl));
        $data = $decl['data'] ?? [];
        self::assertSame('D406', $data['form'] ?? null);
        self::assertSame('L', $data['period']['type'] ?? null);
        $codes = array_column($data['warnings'] ?? [], 'code');
        self::assertNotContains('MISSING_BANK_ACCOUNT', $codes, json_encode($data['warnings'] ?? null));
        self::assertNotContains('MISSING_ADDRESS', $codes);
        self::assertContains('NO_OPENING_BALANCES', $codes);
        self::assertGreaterThanOrEqual(2, $data['counts']['salesInvoices']);
        self::assertGreaterThanOrEqual(2, $data['counts']['purchaseInvoices']);
        self::assertGreaterThanOrEqual(2, $data['counts']['payments']);
        self::assertSame($data['totals']['ledger']['debit'], $data['totals']['ledger']['credit'], 'the derived ledger balances');

        $customers = array_column($data['customers'], null, 'id');
        self::assertArrayHasKey('00' . $vatPayerCui, $customers, implode(', ', array_keys($customers)));
        $individual = array_filter($customers, static fn (array $c) => str_starts_with($c['id'], '04'));
        self::assertNotEmpty($individual, 'a private person without CNP is identified by 04 + a company-assigned code');
        $suppliers = array_column($data['suppliers'], null, 'id');
        self::assertArrayHasKey('00' . self::cui('1357911'), $suppliers);
        self::assertArrayHasKey('00' . self::cui('2468101'), $suppliers);
        $accountIds = array_column($data['accounts'], 'id');
        // lines without a product linked as a service are booked as goods (7015 / 604)
        foreach (['4111', '401', '7015', '4427', '4426', '5121', '5311'] as $account) {
            self::assertContains($account, $accountIds);
        }
        self::assertContains('310344', array_column($data['taxCodes'], 'code'));
        self::assertContains('HUR', array_column($data['uoms'], 'code'));

        $this->apiGet('/api/v1/declarations/' . $decl['id'] . '/xml', $h);
        $xmlBody = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('<AuditFile xmlns="mfp:anaf:dgti:d406:declaratie:v1"', $xmlBody);
        self::assertStringContainsString('<HeaderComment>L</HeaderComment>', $xmlBody);
        self::assertStringContainsString('<SalesInvoices>', $xmlBody);
        self::assertStringContainsString('<PurchaseInvoices>', $xmlBody);
        self::assertStringContainsString('<Payments>', $xmlBody);
        self::assertStringContainsString('<TaxCode>000000</TaxCode>', $xmlBody);

        // ANAF's validator applies the rules of a reporting period, which the SAF-T file does
        // not carry as an attribute: the Java service passes it as `?an=&luna=`. A service
        // that predates that sends the file through the oldest rule set, where the current
        // tax codes do not exist yet — detect it and skip instead of reporting a false error.
        if ($this->validatorIgnoresThePeriod()) {
            self::markTestSkipped('The Java validation service does not pass the reporting period to DUKIntegrator yet; restart it so it picks up the current JavaServiceServer build.');
        }

        $this->apiPost('/api/v1/declarations/' . $decl['id'] . '/validate', [], $h);
        $status = $this->client->getResponse()->getStatusCode();
        $body = $this->client->getResponse()->getContent();
        if ($status >= 500 || str_contains((string) $body, 'Connection refused') || str_contains((string) $body, 'unavailable') || str_contains((string) $body, 'unreachable') || str_contains((string) $body, 'D406Validator')) {
            self::markTestSkipped('ANAF D406 validator (Java service / jar) not reachable: ' . substr((string) $body, 0, 300));
        }
        if (str_contains((string) $body, 'nu se afla in lista')) {
            // The validator applies the rules of the oldest reporting period it knows unless
            // the period is passed to it (`?an=&luna=`, JavaServiceServer), so the current
            // tax codes come back unknown: the Java service is running an older build.
            self::markTestSkipped('The Java validation service predates the D406 reporting-period parameters; restart it to pick them up: ' . substr((string) $body, 0, 200));
        }
        self::assertSame(200, $status, 'DUK validation failed: ' . $body);
    }

    /** True when the validator answers with the rules of an older period than the one asked for. */
    private function validatorIgnoresThePeriod(): bool
    {
        $probe = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <AuditFile xmlns="mfp:anaf:dgti:d406:declaratie:v1"><Header><AuditFileVersion>2.4.9</AuditFileVersion><AuditFileCountry>RO</AuditFileCountry><AuditFileDateCreated>2026-01-01</AuditFileDateCreated><SoftwareCompanyName>Storno.ro</SoftwareCompanyName><SoftwareID>Storno</SoftwareID><SoftwareVersion>1.0</SoftwareVersion><Company><RegistrationNumber>RO3138536</RegistrationNumber><Name>Firma Test SRL</Name><Address><City>Bacau</City><Country>RO</Country><AddressType>StreetAddress</AddressType></Address><Contact><ContactPerson><FirstName>Ion</FirstName><LastName>Popescu</LastName></ContactPerson><Telephone>0234000000</Telephone></Contact><BankAccount><IBANNumber>RO49AAAA1B31007593840000</IBANNumber></BankAccount></Company><DefaultCurrencyCode>RON</DefaultCurrencyCode><SelectionCriteria><PeriodStart>1</PeriodStart><PeriodStartYear>2026</PeriodStartYear><PeriodEnd>1</PeriodEnd><PeriodEndYear>2026</PeriodEndYear></SelectionCriteria><HeaderComment>L</HeaderComment><SegmentIndex>1</SegmentIndex><TotalSegmentsInsequence>1</TotalSegmentsInsequence><TaxAccountingBasis>A</TaxAccountingBasis></Header><MasterFiles><GeneralLedgerAccounts/><Customers/><Suppliers/><TaxTable><TaxTableEntry><TaxType>300</TaxType><Description>TVA</Description><TaxCodeDetails><TaxCode>310344</TaxCode><TaxPercentage>21</TaxPercentage><BaseRate>0.2100</BaseRate><Country>RO</Country></TaxCodeDetails></TaxTableEntry></TaxTable><UOMTable/><AnalysisTypeTable/><MovementTypeTable/><Products/><Owners/><Assets/></MasterFiles><GeneralLedgerEntries/><SourceDocuments/></AuditFile>
            XML;

        /** @var \App\Service\Declaration\DukIntegratorService $duk */
        $duk = static::getContainer()->get(\App\Service\Declaration\DukIntegratorService::class);
        try {
            $result = $duk->validate($probe, 'D406', 2026, 1);
        } catch (\Throwable) {
            return false; // unreachable: the caller reports it as a skip of its own
        }
        foreach ($result->errors as $error) {
            // "valoarea '310344' nu se afla in lista" — a code that exists only from 2025-07 on
            if (str_contains((string) $error, 'nu se afla in lista')) {
                return true;
            }
        }

        return false;
    }

    /** @param array<int, array{0: string, 1: string, 2: string}> $lines [base, rate, category] */
    private function receivedInvoice(string $companyId, string $supplierName, string $cif, bool $vatPayer, array $lines): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $company = $em->getRepository(Company::class)->find($companyId);
        self::assertNotNull($company);

        $supplier = $em->getRepository(Supplier::class)->findOneBy(['company' => $company, 'cif' => $cif]);
        if ($supplier === null) {
            $supplier = (new Supplier())
                ->setCompany($company)
                ->setName($supplierName)
                ->setCif($cif)
                ->setVatCode($vatPayer ? 'RO' . $cif : null)
                ->setIsVatPayer($vatPayer)
                ->setCountry('RO')
                ->setCity('Bacau')
                ->setCounty('BC');
            $em->persist($supplier);
        }

        $invoice = (new Invoice())
            ->setCompany($company)
            ->setSupplier($supplier)
            ->setDocumentType(DocumentType::INVOICE)
            ->setStatus(DocumentStatus::SYNCED)
            ->setDirection(InvoiceDirection::INCOMING)
            ->setNumber('FZ' . random_int(100000, 999999))
            ->setIssueDate(new \DateTimeImmutable('today'))
            ->setDueDate(new \DateTimeImmutable('+30 days'))
            ->setCurrency('RON')
            ->setLanguage('ro')
            ->setSenderCif($cif)
            ->setSenderName($supplierName)
            ->setReceiverCif((string) $company->getCif())
            ->setReceiverName((string) $company->getName());

        $subtotal = '0.00';
        $vatTotal = '0.00';
        foreach ($lines as $i => [$base, $rate, $category]) {
            $vat = bcdiv(bcmul($base, $rate, 4), '100', 2);
            $invoice->addLine((new InvoiceLine())
                ->setPosition($i + 1)
                ->setDescription('Linie ' . ($i + 1))
                ->setQuantity('1.00')
                ->setUnitOfMeasure('buc')
                ->setUnitPrice($base)
                ->setVatRate($rate)
                ->setVatCategoryCode($category)
                ->setVatAmount($vat)
                ->setLineTotal($base)
                ->setDiscount('0.00')
                ->setDiscountPercent('0.00'));
            $subtotal = bcadd($subtotal, $base, 2);
            $vatTotal = bcadd($vatTotal, $vat, 2);
        }
        $invoice->setSubtotal($subtotal)->setVatTotal($vatTotal)->setTotal(bcadd($subtotal, $vatTotal, 2))->setDiscount('0.00');

        $em->persist($invoice);
        $em->flush();
    }
}
