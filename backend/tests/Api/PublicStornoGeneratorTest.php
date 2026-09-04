<?php

declare(strict_types=1);

namespace App\Tests\Api;

class PublicStornoGeneratorTest extends ApiTestCase
{
    private const ENDPOINT = '/api/v1/public/storno-generator';

    public function testGeneratesNegativeInvoiceXmlWithoutAuthentication(): void
    {
        $data = $this->apiPost(self::ENDPOINT, $this->validPayload());

        $this->assertResponseStatusCodeSame(200);
        $this->assertArrayHasKey('xml', $data);
        $this->assertArrayHasKey('valid', $data);
        $this->assertArrayHasKey('errors', $data);
        $this->assertSame('storno-STR-0007.xml', $data['filename']);

        $xml = $data['xml'];
        $this->assertStringContainsString('<Invoice', $xml);
        $this->assertStringContainsString('<cbc:InvoiceTypeCode>380</cbc:InvoiceTypeCode>', $xml);
        $this->assertStringContainsString('<cbc:ID>STR-0007</cbc:ID>', $xml);
        $this->assertStringContainsString('<cac:BillingReference>', $xml);
        $this->assertStringContainsString('<cbc:ID>FCT-0123</cbc:ID>', $xml);
        $this->assertStringContainsString('<cbc:IssueDate>2026-08-10</cbc:IssueDate>', $xml);
        $this->assertStringContainsString('<cbc:InvoicedQuantity unitCode="H87">-2.0000</cbc:InvoicedQuantity>', $xml);
        $this->assertStringContainsString('<cbc:CompanyID>RO12345678</cbc:CompanyID>', $xml);
        $this->assertStringContainsString('<cbc:CountrySubentity>RO-CJ</cbc:CountrySubentity>', $xml);
        $this->assertStringNotContainsString('TaxExchangeRate', $xml);

        // 2 x 100 RON at 21% -> -200.00 net, -42.00 VAT, -242.00 total
        $this->assertSame('-200.00', $data['totals']['subtotal']);
        $this->assertSame('-42.00', $data['totals']['vatTotal']);
        $this->assertSame('-242.00', $data['totals']['total']);

        // XSD structure must pass regardless of the Schematron sidecar being up.
        $xsdErrors = array_filter($data['errors'], static fn (array $e) => ($e['source'] ?? '') === 'xsd');
        $this->assertSame([], array_values($xsdErrors));
    }

    public function testRejectsMissingRequiredFieldsWithFieldErrors(): void
    {
        $payload = $this->validPayload();
        unset($payload['seller']['cif'], $payload['original']['number']);
        $payload['lines'][0]['quantity'] = '0';

        $data = $this->apiPost(self::ENDPOINT, $payload);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSame('VALIDATION_FAILED', $data['code']);
        $this->assertArrayHasKey('seller.cif', $data['fieldErrors']);
        $this->assertArrayHasKey('original.number', $data['fieldErrors']);
        $this->assertArrayHasKey('lines.0.quantity', $data['fieldErrors']);
    }

    public function testRejectsNonRonCurrency(): void
    {
        $payload = $this->validPayload();
        $payload['currency'] = 'EUR';

        $data = $this->apiPost(self::ENDPOINT, $payload);

        $this->assertResponseStatusCodeSame(422);
        $this->assertArrayHasKey('currency', $data['fieldErrors']);
    }

    public function testRejectsInvalidJson(): void
    {
        $this->client->request('POST', self::ENDPOINT, [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], '{not json');

        $this->assertResponseStatusCodeSame(400);
    }

    public function testNonVatPayerSellerUsesCategoryOAndNoVatScheme(): void
    {
        $payload = $this->validPayload();
        $payload['seller']['vatPayer'] = false;
        $payload['lines'][0]['vatRate'] = '0';

        $data = $this->apiPost(self::ENDPOINT, $payload);

        $this->assertResponseStatusCodeSame(200);
        $this->assertStringContainsString('<cbc:ID>NOT_VAT</cbc:ID>', $data['xml']);
        $this->assertStringContainsString('<cbc:ID>O</cbc:ID>', $data['xml']);
        $this->assertSame('0.00', $data['totals']['vatTotal']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(): array
    {
        return [
            'seller' => [
                'name' => 'Exemplu Furnizor SRL',
                'cif' => 'RO12345678',
                'registrationNumber' => 'J12/345/2020',
                'vatPayer' => true,
                'address' => 'Str. Exemplului nr. 1',
                'city' => 'Cluj-Napoca',
                'county' => 'Cluj',
                'country' => 'RO',
            ],
            'buyer' => [
                'type' => 'company',
                'name' => 'Client Test SRL',
                'cui' => '87654321',
                'address' => 'Bd. Unirii nr. 10',
                'city' => 'Sector 3',
                'county' => 'Bucuresti',
                'country' => 'RO',
            ],
            'original' => [
                'number' => 'FCT-0123',
                'issueDate' => '2026-08-10',
            ],
            'storno' => [
                'number' => 'STR-0007',
                'issueDate' => '2026-09-04',
            ],
            'currency' => 'RON',
            'lines' => [
                [
                    'description' => 'Servicii consultanta',
                    'quantity' => '2',
                    'unitOfMeasure' => 'buc',
                    'unitPrice' => '100',
                    'vatRate' => '21',
                ],
            ],
        ];
    }
}
