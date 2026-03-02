<?php

namespace App\Tests\Unit;

use App\Validator\UblExtensionsValidator;
use PHPUnit\Framework\TestCase;

class UblExtensionsValidatorTest extends TestCase
{
    private UblExtensionsValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new UblExtensionsValidator();
    }

    // === Document-level validation ===

    public function testNullReturnsNull(): void
    {
        $this->assertNull($this->validator->validateDocumentExtensions(null));
    }

    public function testEmptyArrayReturnsNull(): void
    {
        $this->assertNull($this->validator->validateDocumentExtensions([]));
    }

    public function testUnknownKeysAreStripped(): void
    {
        $result = $this->validator->validateDocumentExtensions([
            'unknownKey' => 'value',
            'anotherBadKey' => 123,
        ]);
        $this->assertNull($result);
    }

    public function testUnknownKeysStrippedWhileValidKeysPreserved(): void
    {
        $result = $this->validator->validateDocumentExtensions([
            'unknownKey' => 'value',
            'prepaidAmount' => '100.00',
        ]);
        $this->assertNotNull($result);
        $this->assertArrayHasKey('prepaidAmount', $result);
        $this->assertArrayNotHasKey('unknownKey', $result);
    }

    // === InvoicePeriod ===

    public function testValidInvoicePeriod(): void
    {
        $result = $this->validator->validateDocumentExtensions([
            'invoicePeriod' => [
                'startDate' => '2026-03-01',
                'endDate' => '2026-03-31',
                'descriptionCode' => '35',
            ],
        ]);

        $this->assertSame('2026-03-01', $result['invoicePeriod']['startDate']);
        $this->assertSame('2026-03-31', $result['invoicePeriod']['endDate']);
        $this->assertSame('35', $result['invoicePeriod']['descriptionCode']);
    }

    public function testInvoicePeriodStartDateOnly(): void
    {
        $result = $this->validator->validateDocumentExtensions([
            'invoicePeriod' => ['startDate' => '2026-01-15'],
        ]);
        $this->assertSame('2026-01-15', $result['invoicePeriod']['startDate']);
        $this->assertArrayNotHasKey('endDate', $result['invoicePeriod']);
    }

    public function testInvoicePeriodInvalidDateFormat(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('YYYY-MM-DD');

        $this->validator->validateDocumentExtensions([
            'invoicePeriod' => ['startDate' => '03/01/2026'],
        ]);
    }

    public function testInvoicePeriodInvalidDate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid date');

        $this->validator->validateDocumentExtensions([
            'invoicePeriod' => ['startDate' => '2026-02-30'],
        ]);
    }

    public function testInvoicePeriodEmptyObjectThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at least startDate or endDate');

        $this->validator->validateDocumentExtensions([
            'invoicePeriod' => [],
        ]);
    }

    public function testInvoicePeriodNotArrayThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->validator->validateDocumentExtensions([
            'invoicePeriod' => 'not-an-array',
        ]);
    }

    // === Delivery ===

    public function testValidDelivery(): void
    {
        $result = $this->validator->validateDocumentExtensions([
            'delivery' => [
                'actualDeliveryDate' => '2026-03-15',
                'deliveryAddress' => [
                    'streetName' => 'Str. Eroilor 10',
                    'cityName' => 'Cluj-Napoca',
                    'countrySubentity' => 'RO-CJ',
                    'countryCode' => 'RO',
                ],
            ],
        ]);

        $this->assertSame('2026-03-15', $result['delivery']['actualDeliveryDate']);
        $this->assertSame('Str. Eroilor 10', $result['delivery']['deliveryAddress']['streetName']);
        $this->assertSame('RO', $result['delivery']['deliveryAddress']['countryCode']);
    }

    public function testDeliveryDateOnly(): void
    {
        $result = $this->validator->validateDocumentExtensions([
            'delivery' => ['actualDeliveryDate' => '2026-03-15'],
        ]);
        $this->assertSame('2026-03-15', $result['delivery']['actualDeliveryDate']);
        $this->assertArrayNotHasKey('deliveryAddress', $result['delivery']);
    }

    public function testDeliveryInvalidCountryCode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid country code');

        $this->validator->validateDocumentExtensions([
            'delivery' => [
                'deliveryAddress' => ['countryCode' => 'XX'],
            ],
        ]);
    }

    public function testDeliveryEmptyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('actualDeliveryDate or deliveryAddress');

        $this->validator->validateDocumentExtensions([
            'delivery' => [],
        ]);
    }

    // === AllowanceCharges (document-level) ===

    public function testValidAllowanceCharge(): void
    {
        $result = $this->validator->validateDocumentExtensions([
            'allowanceCharges' => [
                [
                    'chargeIndicator' => false,
                    'reasonCode' => '95',
                    'reason' => 'Discount 10%',
                    'amount' => '100.00',
                    'baseAmount' => '1000.00',
                    'multiplierFactorNumeric' => '10.00',
                    'taxCategoryCode' => 'S',
                    'taxRate' => '19.00',
                ],
            ],
        ]);

        $ac = $result['allowanceCharges'][0];
        $this->assertFalse($ac['chargeIndicator']);
        $this->assertSame('100.00', $ac['amount']);
        $this->assertSame('S', $ac['taxCategoryCode']);
        $this->assertSame('19.00', $ac['taxRate']);
        $this->assertSame('95', $ac['reasonCode']);
    }

    public function testAllowanceChargeRequiresChargeIndicator(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('chargeIndicator');

        $this->validator->validateDocumentExtensions([
            'allowanceCharges' => [
                ['amount' => '50.00', 'taxCategoryCode' => 'S', 'taxRate' => '19.00'],
            ],
        ]);
    }

    public function testAllowanceChargeRequiresAmount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('amount is required');

        $this->validator->validateDocumentExtensions([
            'allowanceCharges' => [
                ['chargeIndicator' => false, 'taxCategoryCode' => 'S', 'taxRate' => '19.00'],
            ],
        ]);
    }

    public function testDocumentAllowanceChargeRequiresTaxCategory(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('taxCategoryCode is required');

        $this->validator->validateDocumentExtensions([
            'allowanceCharges' => [
                ['chargeIndicator' => false, 'amount' => '50.00'],
            ],
        ]);
    }

    public function testDocumentAllowanceChargeRequiresTaxRate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('taxRate is required');

        $this->validator->validateDocumentExtensions([
            'allowanceCharges' => [
                ['chargeIndicator' => false, 'amount' => '50.00', 'taxCategoryCode' => 'S'],
            ],
        ]);
    }

    public function testAllowanceChargeInvalidTaxCategory(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('taxCategoryCode');

        $this->validator->validateDocumentExtensions([
            'allowanceCharges' => [
                ['chargeIndicator' => false, 'amount' => '50.00', 'taxCategoryCode' => 'X', 'taxRate' => '19.00'],
            ],
        ]);
    }

    public function testAllowanceChargesMax20(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('max 20');

        $charges = array_fill(0, 21, [
            'chargeIndicator' => true,
            'amount' => '10.00',
            'taxCategoryCode' => 'S',
            'taxRate' => '19.00',
        ]);

        $this->validator->validateDocumentExtensions([
            'allowanceCharges' => $charges,
        ]);
    }

    public function testChargeIndicatorTrue(): void
    {
        $result = $this->validator->validateDocumentExtensions([
            'allowanceCharges' => [
                [
                    'chargeIndicator' => true,
                    'amount' => '25.00',
                    'taxCategoryCode' => 'S',
                    'taxRate' => '19.00',
                ],
            ],
        ]);
        $this->assertTrue($result['allowanceCharges'][0]['chargeIndicator']);
    }

    // === PrepaidAmount ===

    public function testValidPrepaidAmount(): void
    {
        $result = $this->validator->validateDocumentExtensions([
            'prepaidAmount' => '200.00',
        ]);
        $this->assertSame('200.00', $result['prepaidAmount']);
    }

    public function testPrepaidAmountZero(): void
    {
        $result = $this->validator->validateDocumentExtensions([
            'prepaidAmount' => '0.00',
        ]);
        $this->assertSame('0.00', $result['prepaidAmount']);
    }

    public function testPrepaidAmountNegativeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('prepaidAmount');

        $this->validator->validateDocumentExtensions([
            'prepaidAmount' => '-50.00',
        ]);
    }

    public function testPrepaidAmountNonNumericThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->validator->validateDocumentExtensions([
            'prepaidAmount' => 'abc',
        ]);
    }

    // === AdditionalDocumentReferences ===

    public function testValidAdditionalDocumentReferences(): void
    {
        $result = $this->validator->validateDocumentExtensions([
            'additionalDocumentReferences' => [
                [
                    'id' => 'ATT-001',
                    'documentTypeCode' => '916',
                    'documentDescription' => 'Timesheet',
                ],
            ],
        ]);

        $ref = $result['additionalDocumentReferences'][0];
        $this->assertSame('ATT-001', $ref['id']);
        $this->assertSame('916', $ref['documentTypeCode']);
        $this->assertSame('Timesheet', $ref['documentDescription']);
    }

    public function testAdditionalDocRefRequiresId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('id is required');

        $this->validator->validateDocumentExtensions([
            'additionalDocumentReferences' => [
                ['documentTypeCode' => '916'],
            ],
        ]);
    }

    public function testAdditionalDocRefIdMaxLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('max 200');

        $this->validator->validateDocumentExtensions([
            'additionalDocumentReferences' => [
                ['id' => str_repeat('X', 201)],
            ],
        ]);
    }

    public function testAdditionalDocRefsMax10(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('max 10');

        $refs = array_fill(0, 11, ['id' => 'REF']);
        $this->validator->validateDocumentExtensions([
            'additionalDocumentReferences' => $refs,
        ]);
    }

    // === Line-level validation ===

    public function testLineNullReturnsNull(): void
    {
        $this->assertNull($this->validator->validateLineExtensions(null));
    }

    public function testLineEmptyReturnsNull(): void
    {
        $this->assertNull($this->validator->validateLineExtensions([]));
    }

    public function testLineUnknownKeysStripped(): void
    {
        $this->assertNull($this->validator->validateLineExtensions([
            'badKey' => 'value',
        ]));
    }

    public function testLineInvoicePeriod(): void
    {
        $result = $this->validator->validateLineExtensions([
            'invoicePeriod' => [
                'startDate' => '2026-03-01',
                'endDate' => '2026-03-31',
            ],
        ]);
        $this->assertSame('2026-03-01', $result['invoicePeriod']['startDate']);
    }

    public function testLineAllowanceChargeDoesNotRequireTaxCategory(): void
    {
        $result = $this->validator->validateLineExtensions([
            'allowanceCharges' => [
                ['chargeIndicator' => false, 'amount' => '5.00', 'reason' => 'Volume discount'],
            ],
        ]);

        $ac = $result['allowanceCharges'][0];
        $this->assertFalse($ac['chargeIndicator']);
        $this->assertSame('5.00', $ac['amount']);
        $this->assertArrayNotHasKey('taxCategoryCode', $ac);
    }

    public function testLineAllowanceChargesMax10(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('max 10');

        $charges = array_fill(0, 11, [
            'chargeIndicator' => false,
            'amount' => '5.00',
        ]);
        $this->validator->validateLineExtensions([
            'allowanceCharges' => $charges,
        ]);
    }

    // === AdditionalItemProperties ===

    public function testValidAdditionalItemProperties(): void
    {
        $result = $this->validator->validateLineExtensions([
            'additionalItemProperties' => [
                ['name' => 'Color', 'value' => 'Red'],
                ['name' => 'Size', 'value' => 'XL'],
            ],
        ]);

        $this->assertCount(2, $result['additionalItemProperties']);
        $this->assertSame('Color', $result['additionalItemProperties'][0]['name']);
        $this->assertSame('Red', $result['additionalItemProperties'][0]['value']);
    }

    public function testAdditionalItemPropertyRequiresName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('name is required');

        $this->validator->validateLineExtensions([
            'additionalItemProperties' => [
                ['value' => 'Red'],
            ],
        ]);
    }

    public function testAdditionalItemPropertyRequiresValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('value is required');

        $this->validator->validateLineExtensions([
            'additionalItemProperties' => [
                ['name' => 'Color'],
            ],
        ]);
    }

    public function testAdditionalItemPropertyNameMaxLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('name: max 50');

        $this->validator->validateLineExtensions([
            'additionalItemProperties' => [
                ['name' => str_repeat('A', 51), 'value' => 'X'],
            ],
        ]);
    }

    public function testAdditionalItemPropertyValueMaxLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('value: max 100');

        $this->validator->validateLineExtensions([
            'additionalItemProperties' => [
                ['name' => 'Color', 'value' => str_repeat('A', 101)],
            ],
        ]);
    }

    public function testAdditionalItemPropertiesMax20(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('max 20');

        $props = array_fill(0, 21, ['name' => 'K', 'value' => 'V']);
        $this->validator->validateLineExtensions([
            'additionalItemProperties' => $props,
        ]);
    }

    // === OriginCountry ===

    public function testValidOriginCountry(): void
    {
        $result = $this->validator->validateLineExtensions([
            'originCountry' => 'DE',
        ]);
        $this->assertSame('DE', $result['originCountry']);
    }

    public function testOriginCountryLowercaseNormalized(): void
    {
        $result = $this->validator->validateLineExtensions([
            'originCountry' => 'ro',
        ]);
        $this->assertSame('RO', $result['originCountry']);
    }

    public function testOriginCountryInvalidThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid country code');

        $this->validator->validateLineExtensions([
            'originCountry' => 'XX',
        ]);
    }

    // === Full document-level integration ===

    public function testFullDocumentExtensions(): void
    {
        $result = $this->validator->validateDocumentExtensions([
            'invoicePeriod' => ['startDate' => '2026-03-01', 'endDate' => '2026-03-31'],
            'delivery' => ['actualDeliveryDate' => '2026-03-15'],
            'allowanceCharges' => [
                ['chargeIndicator' => false, 'amount' => '100.00', 'taxCategoryCode' => 'S', 'taxRate' => '19.00'],
            ],
            'prepaidAmount' => '200.00',
            'additionalDocumentReferences' => [
                ['id' => 'ATT-001', 'documentTypeCode' => '916'],
            ],
            'unknownKey' => 'stripped',
        ]);

        $this->assertArrayHasKey('invoicePeriod', $result);
        $this->assertArrayHasKey('delivery', $result);
        $this->assertArrayHasKey('allowanceCharges', $result);
        $this->assertArrayHasKey('prepaidAmount', $result);
        $this->assertArrayHasKey('additionalDocumentReferences', $result);
        $this->assertArrayNotHasKey('unknownKey', $result);
    }

    // === Decimal formatting ===

    public function testAmountsNormalizedToTwoDecimals(): void
    {
        $result = $this->validator->validateDocumentExtensions([
            'prepaidAmount' => '100',
        ]);
        $this->assertSame('100.00', $result['prepaidAmount']);
    }

    public function testAllowanceAmountFormatted(): void
    {
        $result = $this->validator->validateDocumentExtensions([
            'allowanceCharges' => [
                ['chargeIndicator' => true, 'amount' => '25.5', 'taxCategoryCode' => 'S', 'taxRate' => '19'],
            ],
        ]);
        $this->assertSame('25.50', $result['allowanceCharges'][0]['amount']);
        $this->assertSame('19.00', $result['allowanceCharges'][0]['taxRate']);
    }
}
