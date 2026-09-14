<?php

namespace App\Tests\Api;

use App\Entity\Company;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\Product;
use App\Entity\Supplier;
use App\Enum\DocumentStatus;
use App\Enum\InvoiceDirection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Builds a D301 (decont special de TVA) for the current month from supplier invoices persisted
 * directly (received invoices are not created through the API) and runs it through ANAF's own
 * validator (DUKIntegrator via the Java service). Skipped when the Java service is down.
 */
class D301EndToEndTest extends ApiTestCase
{
    public function testCurrentMonthSpecialReturnIsPopulatedAndAcceptedByTheAnafValidator(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $before = $this->apiGet('/api/v1/companies/' . $companyId, ['X-Company' => $companyId]);
        $wasVatPayer = (bool) ($before['vatPayer'] ?? $before['company']['vatPayer'] ?? true);

        // D301 is for persons not registered for VAT; the header needs the declarant and a bank account
        $this->apiPatch('/api/v1/companies/' . $companyId, ['vatPayer' => false, 'representative' => 'Popescu Ion', 'representativeRole' => 'Administrator'], ['X-Company' => $companyId]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'company update: ' . $this->client->getResponse()->getContent());

        try {
            $accounts = $this->apiGet('/api/v1/bank-accounts', ['X-Company' => $companyId]);
            $accountList = $accounts['data'] ?? $accounts['items'] ?? $accounts;
            if (!is_array($accountList) || !array_filter($accountList, static fn ($a) => is_array($a) && !empty($a['iban']))) {
                $this->apiPost('/api/v1/bank-accounts', ['bankName' => 'Banca Test', 'iban' => 'RO49AAAA1B31007593840000', 'currency' => 'RON', 'isDefault' => true], ['X-Company' => $companyId]);
                self::assertContains($this->client->getResponse()->getStatusCode(), [200, 201], 'bank account: ' . $this->client->getResponse()->getContent());
            }

            // The kernel is rebooted by every request: take the entity manager only once the API calls are done
            $em = self::getContainer()->get(EntityManagerInterface::class);
            $em->clear();
            // The audit listener would stamp createdBy with the user of the previous request's entity manager
            self::getContainer()->get('security.token_storage')->setToken(null);
            $company = $em->getRepository(Company::class)->find($companyId);
            self::assertNotNull($company);
            self::assertFalse($company->isVatPayer());

            $today = new \DateTimeImmutable('today');
            $service = new Product();
            $service->setCompany($company);
            $service->setName('Serviciu extern ' . Uuid::v4()->toRfc4122());
            $service->setIsService(true);
            $em->persist($service);

            // Goods from Germany (section 1) and a service from the US (section 4, no 4.1)
            $this->persistIncoming($em, $company, 'DE', 'DE-' . random_int(1000, 9999), $today, [['base' => '1000.00', 'product' => null]], 'EUR', '4.9750');
            $this->persistIncoming($em, $company, 'US', 'US-' . random_int(1000, 9999), $today, [['base' => '300.00', 'product' => $service]], 'RON', null);
            $em->flush();

            $decl = $this->apiPost('/api/v1/declarations', [
                'type' => 'd301',
                'year' => (int) $today->format('Y'),
                'month' => (int) $today->format('n'),
            ], ['X-Company' => $companyId]);
            self::assertSame(201, $this->client->getResponse()->getStatusCode(), json_encode($decl));
            $rows = $decl['data']['rows'] ?? [];
            $sections = $decl['data']['sections'] ?? [];
            self::assertNotEmpty($sections, 'the supplier invoices must produce sections');
            $types = array_column($sections, 'tip_operatie');
            self::assertContains('1', $types);
            self::assertContains('4', $types);
            self::assertGreaterThanOrEqual(4975, (int) $rows['baza1'], 'EU goods at the invoice rate');
            $type1 = array_filter($sections, static fn (array $s) => $s['tip_operatie'] === '1');
            self::assertSame(array_sum(array_map('intval', array_column($type1, 'tva'))), (int) $rows['tva1'], 'tva1 is the sum of the section 1 rows, each rounded to whole lei');
            foreach ($type1 as $s) {
                self::assertSame((int) round((int) $s['baza'] * 0.21), (int) $s['tva']);
            }
            self::assertGreaterThanOrEqual(300, (int) $rows['baza4']);
            self::assertSame(23, strlen((string) $rows['nr_evid']));
            self::assertNotContains('COMPANY_IS_VAT_PAYER', array_column($decl['data']['warnings'] ?? [], 'code'));

            $this->apiGet('/api/v1/declarations/' . $decl['id'] . '/xml', ['X-Company' => $companyId]);
            $xmlBody = (string) $this->client->getResponse()->getContent();
            self::assertStringContainsString('declaratie301', $xmlBody);
            self::assertStringContainsString('<sectiune', $xmlBody);
            self::assertStringContainsString('tip_operatie="1"', $xmlBody);

            $result = $this->apiPost('/api/v1/declarations/' . $decl['id'] . '/validate', [], ['X-Company' => $companyId]);
            $status = $this->client->getResponse()->getStatusCode();
            $body = $this->client->getResponse()->getContent();
            if ($status >= 500 || str_contains((string) $body, 'Connection refused') || str_contains((string) $body, 'unavailable')) {
                self::markTestSkipped('ANAF validator (Java service) not reachable: ' . substr((string) $body, 0, 200));
            }
            self::assertSame(200, $status, 'DUK validation failed: ' . $body);
        } finally {
            $this->apiPatch('/api/v1/companies/' . $companyId, ['vatPayer' => $wasVatPayer], ['X-Company' => $companyId]);
        }
    }

    /** @param array<int, array{base: string, product: ?Product}> $lines */
    private function persistIncoming(EntityManagerInterface $em, Company $company, string $country, string $number, \DateTimeImmutable $date, array $lines, string $currency, ?string $rate): void
    {
        $supplier = new Supplier();
        $supplier->setCompany($company);
        $supplier->setName('Furnizor ' . $country . ' ' . Uuid::v4()->toRfc4122());
        $supplier->setCountry($country);
        $supplier->setCif($country . random_int(100000000, 999999999));
        $em->persist($supplier);

        $invoice = new Invoice();
        $invoice->setCompany($company);
        $invoice->setSupplier($supplier);
        $invoice->setDirection(InvoiceDirection::INCOMING);
        $invoice->setStatus(DocumentStatus::SYNCED);
        $invoice->setNumber($number);
        $invoice->setIssueDate(\DateTime::createFromImmutable($date));
        $invoice->setDueDate(\DateTime::createFromImmutable($date->modify('+30 days')));
        $invoice->setCurrency($currency);
        $invoice->setExchangeRate($rate);
        $invoice->setCreatedAt($date->setTime(12, 0));
        $subtotal = '0.00';
        foreach ($lines as $l) {
            $line = new InvoiceLine();
            $line->setDescription('Linie ' . $number);
            $line->setQuantity('1');
            $line->setUnitPrice($l['base']);
            $line->setLineTotal($l['base']);
            $line->setVatRate('0');
            $line->setVatAmount('0.00');
            if ($l['product'] !== null) {
                $line->setProduct($l['product']);
            }
            $invoice->addLine($line);
            $subtotal = bcadd($subtotal, $l['base'], 2);
        }
        $invoice->setSubtotal($subtotal);
        $invoice->setVatTotal('0.00');
        $invoice->setTotal($subtotal);
        $em->persist($invoice);
    }
}
