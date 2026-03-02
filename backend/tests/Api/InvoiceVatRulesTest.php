<?php

namespace App\Tests\Api;

use App\Entity\Client;
use App\Entity\Company;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Tests for OSS VAT rate auto-selection and autoApplyVatRules opt-in.
 *
 * Decision tree:
 * 1. Client is RO → standard company VAT rates
 * 2. Client is foreign EU + VIES valid + company has EU VAT → reverse charge (AE/0%)
 * 3. Client is foreign EU + company is OSS + client NOT VIES valid → destination country's standard VAT rate
 * 4. All other cases → standard company VAT rates
 */
class InvoiceVatRulesTest extends ApiTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        parent::setUp();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->warmVatRateCache();
    }

    /**
     * Pre-warm the EU VAT rate cache so EuVatRateService doesn't need to hit GitHub.
     */
    private function warmVatRateCache(): void
    {
        $cache = static::getContainer()->get(CacheInterface::class);
        $cache->delete('eu_vat_rates');
        $cache->get('eu_vat_rates', function (ItemInterface $item): array {
            $item->expiresAfter(86400);

            return [
                'items' => [
                    'HU' => ['name' => 'Hungary', 'code' => 'HU', 'country_code' => 'HU', 'periods' => [
                        ['effective_from' => '2024-01-01', 'rates' => ['standard' => 27, 'reduced' => 18]],
                    ]],
                    'DE' => ['name' => 'Germany', 'code' => 'DE', 'country_code' => 'DE', 'periods' => [
                        ['effective_from' => '2021-01-01', 'rates' => ['standard' => 19, 'reduced' => 7]],
                    ]],
                    'FR' => ['name' => 'France', 'code' => 'FR', 'country_code' => 'FR', 'periods' => [
                        ['effective_from' => '2014-01-01', 'rates' => ['standard' => 20, 'reduced' => 10]],
                    ]],
                ],
            ];
        });
    }

    private function createForeignEuClient(string $companyId, string $country = 'HU', bool $viesValid = false): string
    {
        $response = $this->apiPost('/api/v1/clients', [
            'name' => 'EU Client ' . $country . ' ' . substr(md5(uniqid()), 0, 6),
            'type' => 'company',
            'cui' => $country . rand(10000000, 99999999),
            'vatCode' => $country . rand(10000000, 99999999),
            'registrationNumber' => 'J' . rand(10, 40) . '/' . rand(100, 9999) . '/2020',
            'address' => 'Test Str. 1',
            'city' => 'Budapest',
            'county' => 'Budapest',
            'country' => $country,
        ], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(201);
        $clientId = $response['client']['id'];

        // Set VIES valid directly via entity manager (not exposed via API)
        if ($viesValid) {
            $this->em->clear();
            $client = $this->em->getRepository(Client::class)->find(Uuid::fromString($clientId));
            $client->setViesValid(true);
            $client->setViesValidatedAt(new \DateTimeImmutable());
            $this->em->flush();
        }

        return $clientId;
    }

    private function createRomanianClient(string $companyId): string
    {
        $response = $this->apiPost('/api/v1/clients', [
            'name' => 'Client RO ' . substr(md5(uniqid()), 0, 6),
            'type' => 'company',
            'cui' => 'RO' . rand(10000000, 99999999),
            'registrationNumber' => 'J40/' . rand(100, 9999) . '/2020',
            'address' => 'Str. Exemplu 1',
            'city' => 'Bucuresti',
            'county' => 'B',
            'country' => 'RO',
        ], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(201);

        return $response['client']['id'];
    }

    private function setCompanyOss(string $companyId, bool $oss): void
    {
        $this->apiPatch('/api/v1/companies/' . $companyId, ['oss' => $oss]);
        $this->assertResponseStatusCodeSame(200);
    }

    private function setCompanyVatIn(string $companyId, ?string $vatIn): void
    {
        $this->apiPatch('/api/v1/companies/' . $companyId, ['vatIn' => $vatIn ?? '']);
        $this->assertResponseStatusCodeSame(200);
    }

    private function standardInvoiceLines(): array
    {
        return [
            [
                'description' => 'Servicii IT',
                'quantity' => '1.00',
                'unitOfMeasure' => 'buc',
                'unitPrice' => '1000.00',
                'vatRate' => '21.00',
                'vatCategoryCode' => 'S',
                'discount' => '0.00',
                'discountPercent' => '0.00',
            ],
        ];
    }

    // ────────────────────────────────────────────────────────
    // Invoice Defaults — OSS response
    // ────────────────────────────────────────────────────────

    public function testDefaultsRomanianClientNoOss(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();
        $clientId = $this->createRomanianClient($companyId);

        $data = $this->apiGet('/api/v1/invoice-defaults?clientId=' . $clientId, ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertFalse($data['reverseCharge']);
        $this->assertFalse($data['ossApplicable']);
        $this->assertNull($data['ossVatRate']);
    }

    public function testDefaultsForeignEuViesValidShowsReverseCharge(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();
        $this->setCompanyVatIn($companyId, 'RO12345678');
        $clientId = $this->createForeignEuClient($companyId, 'DE', viesValid: true);

        $data = $this->apiGet('/api/v1/invoice-defaults?clientId=' . $clientId, ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertTrue($data['reverseCharge']);
        $this->assertFalse($data['ossApplicable']);
        $this->assertNull($data['ossVatRate']);
    }

    public function testDefaultsForeignEuNotViesOssEnabledShowsOss(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();
        $this->setCompanyOss($companyId, true);

        $clientId = $this->createForeignEuClient($companyId, 'HU', viesValid: false);

        $data = $this->apiGet('/api/v1/invoice-defaults?clientId=' . $clientId, ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertFalse($data['reverseCharge']);
        $this->assertTrue($data['ossApplicable']);
        $this->assertNotNull($data['ossVatRate']);
        $this->assertSame('S', $data['ossVatRate']['categoryCode']);
        // Hungary standard rate should be 27
        $this->assertSame('27', $data['ossVatRate']['rate']);

        $this->setCompanyOss($companyId, false);
    }

    public function testDefaultsForeignEuNotViesOssDisabledNoOss(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();
        $this->setCompanyOss($companyId, false);
        $clientId = $this->createForeignEuClient($companyId, 'HU', viesValid: false);

        $data = $this->apiGet('/api/v1/invoice-defaults?clientId=' . $clientId, ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertFalse($data['reverseCharge']);
        $this->assertFalse($data['ossApplicable']);
        $this->assertNull($data['ossVatRate']);
    }

    public function testDefaultsOssMultipleCountries(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();
        $this->setCompanyOss($companyId, true);

        // Germany — 19%
        $deClientId = $this->createForeignEuClient($companyId, 'DE', viesValid: false);
        $data = $this->apiGet('/api/v1/invoice-defaults?clientId=' . $deClientId, ['X-Company' => $companyId]);
        $this->assertTrue($data['ossApplicable']);
        $this->assertSame('19', $data['ossVatRate']['rate']);

        // France — 20%
        $frClientId = $this->createForeignEuClient($companyId, 'FR', viesValid: false);
        $data = $this->apiGet('/api/v1/invoice-defaults?clientId=' . $frClientId, ['X-Company' => $companyId]);
        $this->assertTrue($data['ossApplicable']);
        $this->assertSame('20', $data['ossVatRate']['rate']);

        $this->setCompanyOss($companyId, false);
    }

    // ────────────────────────────────────────────────────────
    // Invoice create — autoApplyVatRules: false (default)
    // ────────────────────────────────────────────────────────

    public function testCreateInvoiceNoAutoApplyKeepsCallerRates(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();
        $this->setCompanyVatIn($companyId, 'RO12345678');
        $clientId = $this->createForeignEuClient($companyId, 'DE', viesValid: true);

        $data = $this->apiPost('/api/v1/invoices', [
            'clientId' => $clientId,
            'documentType' => 'invoice',
            'issueDate' => date('Y-m-d'),
            'lines' => $this->standardInvoiceLines(),
        ], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(201);
        $invoice = $data['invoice'];
        $this->assertSame('S', $invoice['lines'][0]['vatCategoryCode']);
        $this->assertSame('21.00', $invoice['lines'][0]['vatRate']);
    }

    // ────────────────────────────────────────────────────────
    // Invoice create — autoApplyVatRules: true
    // ────────────────────────────────────────────────────────

    public function testCreateInvoiceAutoApplyReverseCharge(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();
        $this->setCompanyVatIn($companyId, 'RO12345678');
        $clientId = $this->createForeignEuClient($companyId, 'DE', viesValid: true);

        $data = $this->apiPost('/api/v1/invoices', [
            'clientId' => $clientId,
            'documentType' => 'invoice',
            'issueDate' => date('Y-m-d'),
            'autoApplyVatRules' => true,
            'lines' => $this->standardInvoiceLines(),
        ], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(201);
        $invoice = $data['invoice'];
        $this->assertSame('AE', $invoice['lines'][0]['vatCategoryCode']);
        $this->assertSame('0.00', $invoice['lines'][0]['vatRate']);
    }

    public function testCreateInvoiceAutoApplyOss(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();
        $this->setCompanyOss($companyId, true);
        $clientId = $this->createForeignEuClient($companyId, 'HU', viesValid: false);

        $data = $this->apiPost('/api/v1/invoices', [
            'clientId' => $clientId,
            'documentType' => 'invoice',
            'issueDate' => date('Y-m-d'),
            'autoApplyVatRules' => true,
            'lines' => $this->standardInvoiceLines(),
        ], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(201);
        $invoice = $data['invoice'];
        $this->assertSame('S', $invoice['lines'][0]['vatCategoryCode']);
        $this->assertSame('27.00', $invoice['lines'][0]['vatRate']);

        $this->setCompanyOss($companyId, false);
    }

    public function testCreateInvoiceAutoApplyRomanianClientNoChange(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();
        $this->setCompanyOss($companyId, true);
        $clientId = $this->createRomanianClient($companyId);

        $data = $this->apiPost('/api/v1/invoices', [
            'clientId' => $clientId,
            'documentType' => 'invoice',
            'issueDate' => date('Y-m-d'),
            'autoApplyVatRules' => true,
            'lines' => $this->standardInvoiceLines(),
        ], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(201);
        $invoice = $data['invoice'];
        $this->assertSame('S', $invoice['lines'][0]['vatCategoryCode']);
        $this->assertSame('21.00', $invoice['lines'][0]['vatRate']);

        $this->setCompanyOss($companyId, false);
    }

    public function testCreateInvoiceAutoApplyMultipleLines(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();
        $this->setCompanyOss($companyId, true);
        $clientId = $this->createForeignEuClient($companyId, 'DE', viesValid: false);

        $data = $this->apiPost('/api/v1/invoices', [
            'clientId' => $clientId,
            'documentType' => 'invoice',
            'issueDate' => date('Y-m-d'),
            'autoApplyVatRules' => true,
            'lines' => [
                [
                    'description' => 'Service A',
                    'quantity' => '1.00',
                    'unitOfMeasure' => 'buc',
                    'unitPrice' => '500.00',
                    'vatRate' => '21.00',
                    'vatCategoryCode' => 'S',
                    'discount' => '0.00',
                    'discountPercent' => '0.00',
                ],
                [
                    'description' => 'Service B (exempt)',
                    'quantity' => '2.00',
                    'unitOfMeasure' => 'buc',
                    'unitPrice' => '300.00',
                    'vatRate' => '0.00',
                    'vatCategoryCode' => 'Z',
                    'discount' => '0.00',
                    'discountPercent' => '0.00',
                ],
                [
                    'description' => 'Service C',
                    'quantity' => '1.00',
                    'unitOfMeasure' => 'buc',
                    'unitPrice' => '200.00',
                    'vatRate' => '9.00',
                    'vatCategoryCode' => 'S',
                    'discount' => '0.00',
                    'discountPercent' => '0.00',
                ],
            ],
        ], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(201);
        $invoice = $data['invoice'];
        $this->assertCount(3, $invoice['lines']);

        // Line A: S → stays S but rate changes to DE 19%
        $this->assertSame('S', $invoice['lines'][0]['vatCategoryCode']);
        $this->assertSame('19.00', $invoice['lines'][0]['vatRate']);

        // Line B: Z (exempt) → not touched
        $this->assertSame('Z', $invoice['lines'][1]['vatCategoryCode']);
        $this->assertSame('0.00', $invoice['lines'][1]['vatRate']);

        // Line C: S → stays S but rate changes to DE 19%
        $this->assertSame('S', $invoice['lines'][2]['vatCategoryCode']);
        $this->assertSame('19.00', $invoice['lines'][2]['vatRate']);

        $this->setCompanyOss($companyId, false);
    }
}
