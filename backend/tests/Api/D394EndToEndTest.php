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
 * Builds a D394 for the current month from real invoices (issued through the API, received
 * ones written directly) and runs it through ANAF's own validator (DUKIntegrator via the
 * Java service). Skipped when the Java service is down.
 */
class D394EndToEndTest extends ApiTestCase
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

    public function testCurrentMonthD394IsPopulatedAndAcceptedByTheAnafValidator(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();
        $h = ['X-Company' => $companyId];

        // The header needs the declarant and the CAEN code (address and phone come from the fixtures)
        $this->apiPatch('/api/v1/companies/' . $companyId, ['representative' => 'Popescu Ion', 'representativeRole' => 'Administrator', 'caenCode' => '6201'], $h);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'company update: ' . $this->client->getResponse()->getContent());

        // Partners: a RO VAT payer (tip_partener 1) and a private person without CNP (tip_partener 2, declared by county)
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
        foreach ([
            [$companyClientId, [
                ['description' => 'Servicii consultanta', 'quantity' => '1.00', 'unitOfMeasure' => 'buc', 'unitPrice' => '1000.00', 'vatRate' => '21.00', 'vatCategoryCode' => 'S'],
                ['description' => 'Chirie scutita', 'quantity' => '1.00', 'unitOfMeasure' => 'buc', 'unitPrice' => '100.00', 'vatRate' => '0.00', 'vatCategoryCode' => 'E'],
            ]],
            [$individualClientId, [
                ['description' => 'Produs', 'quantity' => '1.00', 'unitOfMeasure' => 'buc', 'unitPrice' => '50.00', 'vatRate' => '21.00', 'vatCategoryCode' => 'S'],
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
        }

        // Received invoices: from a VAT payer (A / AS) and from a supplier not registered for VAT (N)
        $this->receivedInvoice($companyId, 'Furnizor Test SRL', self::cui('1357911'), true, [['300.00', '21.00', 'S'], ['20.00', '0.00', 'E']]);
        $this->receivedInvoice($companyId, 'Mic Furnizor SRL', self::cui('2468101'), false, [['80.00', '0.00', 'E']]);

        $decl = $this->apiPost('/api/v1/declarations', [
            'type' => 'd394',
            'year' => (int) $today->format('Y'),
            'month' => (int) $today->format('n'),
        ], $h);
        self::assertSame(201, $this->client->getResponse()->getStatusCode(), json_encode($decl));
        $data = $decl['data'] ?? [];
        self::assertSame('D394', $data['form'] ?? null);
        self::assertSame(1, $data['header']['op_efectuate'] ?? null);
        // other tests leave invoices to partners without identifiers in the same month; those are reported, not fatal
        $missing = array_filter(array_column($data['warnings'] ?? [], 'code'), static fn (string $c) => str_starts_with($c, 'MISSING_'));
        self::assertSame([], array_values($missing), 'header prerequisites: ' . json_encode($data['warnings'] ?? null));

        $rows = [];
        foreach ($data['partners'] as $row) {
            $rows[$row['tip'] . '|' . $row['tip_partener'] . '|' . $row['cota'] . '|' . $row['cuiP']] = $row;
        }
        self::assertGreaterThanOrEqual(1000, $rows['L|1|21|' . $vatPayerCui]['baza'] ?? 0, json_encode(array_keys($rows)));
        self::assertGreaterThanOrEqual(210, $rows['L|1|21|' . $vatPayerCui]['tva'] ?? 0);
        self::assertGreaterThanOrEqual(100, $rows['LS|1|0|' . $vatPayerCui]['baza'] ?? 0);
        self::assertMatchesRegularExpression('/^\d{2}$/', (string) ($rows['L|2|21|']['judP'] ?? ''), 'PF without CNP are declared as one row with a numeric county code');
        self::assertSame('RO', $rows['L|2|21|']['taraP'] ?? null);
        self::assertGreaterThanOrEqual(300, $rows['A|1|21|' . self::cui('1357911')]['baza'] ?? 0);
        self::assertGreaterThanOrEqual(20, $rows['AS|1|0|' . self::cui('1357911')]['baza'] ?? 0);
        self::assertGreaterThanOrEqual(80, $rows['N|2|0|' . self::cui('2468101')]['baza'] ?? 0);
        self::assertSame(1, $rows['N|2|0|' . self::cui('2468101')]['tip_document'] ?? null);
        self::assertGreaterThanOrEqual(2, $data['informatii']['nrFacturi']);
        self::assertNotEmpty($data['serieFacturi']);

        $this->apiGet('/api/v1/declarations/' . $decl['id'] . '/xml', $h);
        $xmlBody = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('declaratie394', $xmlBody);
        self::assertStringContainsString('<informatii', $xmlBody);
        self::assertStringContainsString('<rezumat1', $xmlBody);
        self::assertStringContainsString('<rezumat2', $xmlBody);
        self::assertStringContainsString('<op1', $xmlBody);
        self::assertStringContainsString('totalPlata_A="', $xmlBody);
        self::assertStringNotContainsString('d_rec', $xmlBody);
        self::assertStringNotContainsString('nrParteneri', $xmlBody);

        $this->apiPost('/api/v1/declarations/' . $decl['id'] . '/validate', [], $h);
        $status = $this->client->getResponse()->getStatusCode();
        $body = $this->client->getResponse()->getContent();
        if ($status >= 500 || str_contains((string) $body, 'Connection refused') || str_contains((string) $body, 'unavailable') || str_contains((string) $body, 'unreachable')) {
            self::markTestSkipped('ANAF validator (Java service) not reachable: ' . substr((string) $body, 0, 200));
        }
        self::assertSame(200, $status, 'DUK validation failed: ' . $body);
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
