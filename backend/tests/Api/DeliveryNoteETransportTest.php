<?php

namespace App\Tests\Api;

class DeliveryNoteETransportTest extends ApiTestCase
{
    /**
     * Create a document series of type 'delivery_note' for the given company.
     */
    private function createDeliveryNoteSeries(string $companyId): array
    {
        $prefix = 'AVZ' . substr(md5(uniqid()), 0, 3);
        $series = $this->apiPost('/api/v1/document-series', [
            'prefix' => $prefix,
            'type' => 'delivery_note',
            'currentNumber' => 0,
        ], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(201);

        return $series;
    }

    /**
     * Create a draft delivery note that includes a full set of e-Transport header fields.
     */
    private function createDraftDeliveryNoteWithETransport(string $companyId, ?string $seriesId = null): array
    {
        $body = [
            'issueDate' => '2026-02-23',
            'dueDate' => '2026-03-23',
            'currency' => 'RON',
            'notes' => 'Test aviz cu e-Transport',
            // e-Transport header fields
            'etransportOperationType' => 30,
            'etransportVehicleNumber' => 'B123ABC',
            'etransportTrailer1' => 'B001REM',
            'etransportTransporterCountry' => 'RO',
            'etransportTransporterCode' => 'RO12345678',
            'etransportTransporterName' => 'Transportator SRL',
            'etransportTransportDate' => '2026-02-24',
            'etransportStartCounty' => 40,
            'etransportStartLocality' => 'Bucuresti',
            'etransportStartStreet' => 'Calea Victoriei',
            'etransportStartNumber' => '100',
            'etransportStartPostalCode' => '010065',
            'etransportEndCounty' => 1,
            'etransportEndLocality' => 'Alba Iulia',
            'etransportEndStreet' => 'Str. Unirii',
            'etransportEndNumber' => '1',
            'etransportEndPostalCode' => '510009',
            'lines' => [
                [
                    'description' => 'Marfa transport',
                    'quantity' => '100.00',
                    'unitOfMeasure' => 'buc',
                    'unitPrice' => '50.00',
                    'vatRate' => '19.00',
                    'vatCategoryCode' => 'S',
                    'discount' => '0.00',
                    'discountPercent' => '0.00',
                ],
            ],
        ];

        if ($seriesId) {
            $body['documentSeriesId'] = $seriesId;
        }

        $data = $this->apiPost('/api/v1/delivery-notes', $body, ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(201);

        return $data['deliveryNote'];
    }

    public function testCreateWithETransportFields(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $data = $this->createDraftDeliveryNoteWithETransport($companyId);

        $this->assertArrayHasKey('id', $data);
        $this->assertArrayHasKey('number', $data);
        $this->assertEquals('draft', $data['status']);
        $this->assertStringStartsWith('AVIZ-', $data['number']);

        // Verify e-Transport header fields are persisted and returned
        $this->assertEquals(30, $data['etransportOperationType']);
        $this->assertEquals('B123ABC', $data['etransportVehicleNumber']);
        $this->assertEquals('B001REM', $data['etransportTrailer1']);
        $this->assertEquals('RO', $data['etransportTransporterCountry']);
        $this->assertEquals('RO12345678', $data['etransportTransporterCode']);
        $this->assertEquals('Transportator SRL', $data['etransportTransporterName']);

        // Route start
        $this->assertEquals(40, $data['etransportStartCounty']);
        $this->assertEquals('Bucuresti', $data['etransportStartLocality']);
        $this->assertEquals('Calea Victoriei', $data['etransportStartStreet']);
        $this->assertEquals('100', $data['etransportStartNumber']);
        $this->assertEquals('010065', $data['etransportStartPostalCode']);

        // Route end
        $this->assertEquals(1, $data['etransportEndCounty']);
        $this->assertEquals('Alba Iulia', $data['etransportEndLocality']);
        $this->assertEquals('Str. Unirii', $data['etransportEndStreet']);
        $this->assertEquals('1', $data['etransportEndNumber']);
        $this->assertEquals('510009', $data['etransportEndPostalCode']);

        // ANAF tracking fields should be null on a fresh draft
        $this->assertNull($data['etransportUit']);
        $this->assertNull($data['etransportStatus']);
        $this->assertNull($data['etransportSubmittedAt']);

        // Verify totals (100 × 50 = 5000, VAT 19% = 950, total = 5950)
        $this->assertCount(1, $data['lines']);
        $this->assertEquals('5000.00', $data['subtotal']);
        $this->assertEquals('950.00', $data['vatTotal']);
        $this->assertEquals('5950.00', $data['total']);
    }

    public function testUpdateETransportFields(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $created = $this->createDraftDeliveryNoteWithETransport($companyId);

        $response = $this->apiPut('/api/v1/delivery-notes/' . $created['id'], [
            'etransportOperationType' => 10,
            'etransportVehicleNumber' => 'CJ99ZZZ',
            'etransportTrailer1' => null,
            'etransportTransporterCode' => 'RO98765432',
            'etransportTransporterName' => 'Alt Transportator SRL',
            'etransportTransportDate' => '2026-03-01',
            'etransportStartCounty' => 12,
            'etransportStartLocality' => 'Cluj-Napoca',
            'etransportEndCounty' => 5,
            'etransportEndLocality' => 'Brasov',
            'lines' => [
                [
                    'description' => 'Marfa transport modificata',
                    'quantity' => '50.00',
                    'unitOfMeasure' => 'buc',
                    'unitPrice' => '80.00',
                    'vatRate' => '19.00',
                    'vatCategoryCode' => 'S',
                    'discount' => '0.00',
                    'discountPercent' => '0.00',
                ],
            ],
        ], ['X-Company' => $companyId]);
        $updated = $response['deliveryNote'];

        $this->assertResponseStatusCodeSame(200);

        // Verify updated e-Transport fields
        $this->assertEquals(10, $updated['etransportOperationType']);
        $this->assertEquals('CJ99ZZZ', $updated['etransportVehicleNumber']);
        $this->assertNull($updated['etransportTrailer1']);
        $this->assertEquals('RO98765432', $updated['etransportTransporterCode']);
        $this->assertEquals('Alt Transportator SRL', $updated['etransportTransporterName']);
        $this->assertEquals(12, $updated['etransportStartCounty']);
        $this->assertEquals('Cluj-Napoca', $updated['etransportStartLocality']);
        $this->assertEquals(5, $updated['etransportEndCounty']);
        $this->assertEquals('Brasov', $updated['etransportEndLocality']);

        // Verify recalculated totals (50 × 80 = 4000, VAT 19% = 760, total = 4760)
        $this->assertCount(1, $updated['lines']);
        $this->assertEquals('4000.00', $updated['subtotal']);
        $this->assertEquals('760.00', $updated['vatTotal']);
        $this->assertEquals('4760.00', $updated['total']);
    }

    public function testCreateWithETransportLineFields(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $response = $this->apiPost('/api/v1/delivery-notes', [
            'issueDate' => '2026-02-23',
            'currency' => 'RON',
            'etransportOperationType' => 30,
            'etransportVehicleNumber' => 'B500ETR',
            'etransportStartCounty' => 40,
            'etransportStartLocality' => 'Bucuresti',
            'etransportEndCounty' => 6,
            'etransportEndLocality' => 'Buzau',
            'lines' => [
                [
                    'description' => 'Produs cu tarif vamal',
                    'quantity' => '10.00',
                    'unitOfMeasure' => 'kg',
                    'unitPrice' => '200.00',
                    'vatRate' => '19.00',
                    'vatCategoryCode' => 'S',
                    'discount' => '0.00',
                    'discountPercent' => '0.00',
                    // e-Transport line fields
                    'tariffCode' => '84818099',
                    'purposeCode' => 101,
                    'unitOfMeasureCode' => 'KGM',
                    'netWeight' => '9.50',
                    'grossWeight' => '10.20',
                    'valueWithoutVat' => '2000.00',
                ],
            ],
        ], ['X-Company' => $companyId]);
        $data = $response['deliveryNote'];

        $this->assertResponseStatusCodeSame(201);
        $this->assertCount(1, $data['lines']);

        $line = $data['lines'][0];
        $this->assertEquals('84818099', $line['tariffCode']);
        $this->assertEquals(101, $line['purposeCode']);
        $this->assertEquals('KGM', $line['unitOfMeasureCode']);
        $this->assertEquals('9.50', $line['netWeight']);
        $this->assertEquals('10.20', $line['grossWeight']);
        $this->assertEquals('2000.00', $line['valueWithoutVat']);
    }

    public function testCreateWithMultipleLinesAndETransportLineFields(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $response = $this->apiPost('/api/v1/delivery-notes', [
            'issueDate' => '2026-02-23',
            'currency' => 'RON',
            'etransportOperationType' => 30,
            'etransportVehicleNumber' => 'B777TRN',
            'etransportStartCounty' => 40,
            'etransportStartLocality' => 'Bucuresti',
            'etransportEndCounty' => 51,
            'etransportEndLocality' => 'Timisoara',
            'lines' => [
                [
                    'description' => 'Produs A',
                    'quantity' => '5.00',
                    'unitOfMeasure' => 'buc',
                    'unitPrice' => '100.00',
                    'vatRate' => '19.00',
                    'vatCategoryCode' => 'S',
                    'discount' => '0.00',
                    'discountPercent' => '0.00',
                    'tariffCode' => '12345678',
                    'unitOfMeasureCode' => 'PCE',
                    'netWeight' => '2.50',
                    'grossWeight' => '3.00',
                ],
                [
                    'description' => 'Produs B',
                    'quantity' => '3.00',
                    'unitOfMeasure' => 'kg',
                    'unitPrice' => '50.00',
                    'vatRate' => '19.00',
                    'vatCategoryCode' => 'S',
                    'discount' => '0.00',
                    'discountPercent' => '0.00',
                    'tariffCode' => '87654321',
                    'unitOfMeasureCode' => 'KGM',
                    'netWeight' => '3.00',
                    'grossWeight' => '3.20',
                    'valueWithoutVat' => '150.00',
                ],
            ],
        ], ['X-Company' => $companyId]);
        $data = $response['deliveryNote'];

        $this->assertResponseStatusCodeSame(201);
        $this->assertCount(2, $data['lines']);

        $lineA = $data['lines'][0];
        $this->assertEquals('Produs A', $lineA['description']);
        $this->assertEquals('12345678', $lineA['tariffCode']);
        $this->assertEquals('PCE', $lineA['unitOfMeasureCode']);
        $this->assertEquals('2.50', $lineA['netWeight']);
        $this->assertEquals('3.00', $lineA['grossWeight']);

        $lineB = $data['lines'][1];
        $this->assertEquals('Produs B', $lineB['description']);
        $this->assertEquals('87654321', $lineB['tariffCode']);
        $this->assertEquals('KGM', $lineB['unitOfMeasureCode']);
        $this->assertEquals('3.00', $lineB['netWeight']);
        $this->assertEquals('3.20', $lineB['grossWeight']);
        $this->assertEquals('150.00', $lineB['valueWithoutVat']);

        // Verify aggregated totals: (5×100 + 3×50) = 650, VAT 19% = 123.50, total = 773.50
        $this->assertEquals('650.00', $data['subtotal']);
        $this->assertEquals('123.50', $data['vatTotal']);
        $this->assertEquals('773.50', $data['total']);
    }

    public function testSubmitETransportRequiresIssuedStatus(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        // Create a draft — do NOT issue it
        $draft = $this->createDraftDeliveryNoteWithETransport($companyId);
        $this->assertEquals('draft', $draft['status']);

        // submit-etransport on a draft must be rejected
        $result = $this->apiPost(
            '/api/v1/delivery-notes/' . $draft['id'] . '/submit-etransport',
            [],
            ['X-Company' => $companyId]
        );

        $this->assertResponseStatusCodeSame(422);
        $this->assertArrayHasKey('error', $result);
    }

    public function testSubmitETransportOnIssuedNote(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        // Create a series and issue the delivery note
        $series = $this->createDeliveryNoteSeries($companyId);
        $draft = $this->createDraftDeliveryNoteWithETransport($companyId, $series['id']);

        $this->apiPost('/api/v1/delivery-notes/' . $draft['id'] . '/issue', [], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);

        // Submit to e-Transport
        $result = $this->apiPost(
            '/api/v1/delivery-notes/' . $draft['id'] . '/submit-etransport',
            [],
            ['X-Company' => $companyId]
        );

        $this->assertResponseStatusCodeSame(200);

        // After submission the status should be 'uploaded' (async processing pending)
        $this->assertEquals('uploaded', $result['etransportStatus']);
        $this->assertNotNull($result['etransportSubmittedAt']);
    }

    public function testSubmitETransportAlreadySubmittedRejected(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $series = $this->createDeliveryNoteSeries($companyId);
        $draft = $this->createDraftDeliveryNoteWithETransport($companyId, $series['id']);

        $this->apiPost('/api/v1/delivery-notes/' . $draft['id'] . '/issue', [], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);

        // First submission must succeed
        $this->apiPost(
            '/api/v1/delivery-notes/' . $draft['id'] . '/submit-etransport',
            [],
            ['X-Company' => $companyId]
        );
        $this->assertResponseStatusCodeSame(200);

        // Second submission on same note must be rejected (status is now 'uploaded')
        $result = $this->apiPost(
            '/api/v1/delivery-notes/' . $draft['id'] . '/submit-etransport',
            [],
            ['X-Company' => $companyId]
        );

        $this->assertResponseStatusCodeSame(422);
        $this->assertArrayHasKey('error', $result);
    }

    public function testCreateWithoutETransportFieldsHasNullDefaults(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $response = $this->apiPost('/api/v1/delivery-notes', [
            'issueDate' => '2026-02-23',
            'currency' => 'RON',
            'lines' => [
                [
                    'description' => 'Produs simplu',
                    'quantity' => '1.00',
                    'unitOfMeasure' => 'buc',
                    'unitPrice' => '100.00',
                    'vatRate' => '19.00',
                    'vatCategoryCode' => 'S',
                    'discount' => '0.00',
                    'discountPercent' => '0.00',
                ],
            ],
        ], ['X-Company' => $companyId]);
        $data = $response['deliveryNote'];

        $this->assertResponseStatusCodeSame(201);

        // All e-Transport header fields should default to null
        $this->assertNull($data['etransportOperationType']);
        $this->assertNull($data['etransportVehicleNumber']);
        $this->assertNull($data['etransportTrailer1']);
        $this->assertNull($data['etransportTrailer2']);
        $this->assertNull($data['etransportTransporterCountry']);
        $this->assertNull($data['etransportTransporterCode']);
        $this->assertNull($data['etransportTransporterName']);
        $this->assertNull($data['etransportTransportDate']);
        $this->assertNull($data['etransportStartCounty']);
        $this->assertNull($data['etransportStartLocality']);
        $this->assertNull($data['etransportEndCounty']);
        $this->assertNull($data['etransportEndLocality']);
        $this->assertNull($data['etransportUit']);
        $this->assertNull($data['etransportStatus']);
        $this->assertNull($data['etransportSubmittedAt']);

        // Line e-Transport fields should also be null
        $line = $data['lines'][0];
        $this->assertNull($line['tariffCode']);
        $this->assertNull($line['purposeCode']);
        $this->assertNull($line['unitOfMeasureCode']);
        $this->assertNull($line['netWeight']);
        $this->assertNull($line['grossWeight']);
        $this->assertNull($line['valueWithoutVat']);
    }

    public function testETransportFieldsNotModifiedOnIssuedNote(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $series = $this->createDeliveryNoteSeries($companyId);
        $draft = $this->createDraftDeliveryNoteWithETransport($companyId, $series['id']);

        // Issue the delivery note
        $issued = $this->apiPost('/api/v1/delivery-notes/' . $draft['id'] . '/issue', [], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);
        $this->assertEquals('issued', $issued['status']);

        // Verify e-Transport fields are preserved after issuing
        $this->assertEquals(30, $issued['etransportOperationType']);
        $this->assertEquals('B123ABC', $issued['etransportVehicleNumber']);
        $this->assertEquals(40, $issued['etransportStartCounty']);
        $this->assertEquals('Bucuresti', $issued['etransportStartLocality']);
        $this->assertEquals(1, $issued['etransportEndCounty']);
        $this->assertEquals('Alba Iulia', $issued['etransportEndLocality']);

        // e-Transport submission fields still null before submit
        $this->assertNull($issued['etransportUit']);
        $this->assertNull($issued['etransportStatus']);
        $this->assertNull($issued['etransportSubmittedAt']);
    }
}
