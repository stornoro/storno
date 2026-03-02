<?php

namespace App\Validator;

class UblExtensionsValidator
{
    private const VALID_TAX_CATEGORY_CODES = ['S', 'Z', 'E', 'AE', 'K', 'G', 'O'];

    private const VALID_COUNTRY_CODES = [
        'AD', 'AE', 'AF', 'AG', 'AI', 'AL', 'AM', 'AO', 'AQ', 'AR', 'AS', 'AT', 'AU', 'AW', 'AX', 'AZ',
        'BA', 'BB', 'BD', 'BE', 'BF', 'BG', 'BH', 'BI', 'BJ', 'BL', 'BM', 'BN', 'BO', 'BQ', 'BR', 'BS',
        'BT', 'BV', 'BW', 'BY', 'BZ', 'CA', 'CC', 'CD', 'CF', 'CG', 'CH', 'CI', 'CK', 'CL', 'CM', 'CN',
        'CO', 'CR', 'CU', 'CV', 'CW', 'CX', 'CY', 'CZ', 'DE', 'DJ', 'DK', 'DM', 'DO', 'DZ', 'EC', 'EE',
        'EG', 'EH', 'ER', 'ES', 'ET', 'FI', 'FJ', 'FK', 'FM', 'FO', 'FR', 'GA', 'GB', 'GD', 'GE', 'GF',
        'GG', 'GH', 'GI', 'GL', 'GM', 'GN', 'GP', 'GQ', 'GR', 'GS', 'GT', 'GU', 'GW', 'GY', 'HK', 'HM',
        'HN', 'HR', 'HT', 'HU', 'ID', 'IE', 'IL', 'IM', 'IN', 'IO', 'IQ', 'IR', 'IS', 'IT', 'JE', 'JM',
        'JO', 'JP', 'KE', 'KG', 'KH', 'KI', 'KM', 'KN', 'KP', 'KR', 'KW', 'KY', 'KZ', 'LA', 'LB', 'LC',
        'LI', 'LK', 'LR', 'LS', 'LT', 'LU', 'LV', 'LY', 'MA', 'MC', 'MD', 'ME', 'MF', 'MG', 'MH', 'MK',
        'ML', 'MM', 'MN', 'MO', 'MP', 'MQ', 'MR', 'MS', 'MT', 'MU', 'MV', 'MW', 'MX', 'MY', 'MZ', 'NA',
        'NC', 'NE', 'NF', 'NG', 'NI', 'NL', 'NO', 'NP', 'NR', 'NU', 'NZ', 'OM', 'PA', 'PE', 'PF', 'PG',
        'PH', 'PK', 'PL', 'PM', 'PN', 'PR', 'PS', 'PT', 'PW', 'PY', 'QA', 'RE', 'RO', 'RS', 'RU', 'RW',
        'SA', 'SB', 'SC', 'SD', 'SE', 'SG', 'SH', 'SI', 'SJ', 'SK', 'SL', 'SM', 'SN', 'SO', 'SR', 'SS',
        'ST', 'SV', 'SX', 'SY', 'SZ', 'TC', 'TD', 'TF', 'TG', 'TH', 'TJ', 'TK', 'TL', 'TM', 'TN', 'TO',
        'TR', 'TT', 'TV', 'TW', 'TZ', 'UA', 'UG', 'UM', 'US', 'UY', 'UZ', 'VA', 'VC', 'VE', 'VG', 'VI',
        'VN', 'VU', 'WF', 'WS', 'YE', 'YT', 'ZA', 'ZM', 'ZW',
    ];

    private const DOCUMENT_KEYS = ['invoicePeriod', 'delivery', 'allowanceCharges', 'prepaidAmount', 'additionalDocumentReferences'];
    private const LINE_KEYS = ['invoicePeriod', 'allowanceCharges', 'additionalItemProperties', 'originCountry'];

    /**
     * Validate and sanitize document-level ublExtensions.
     * Returns cleaned array or null. Throws on invalid values.
     */
    public function validateDocumentExtensions(?array $data): ?array
    {
        if ($data === null || $data === []) {
            return null;
        }

        $result = [];

        foreach ($data as $key => $value) {
            if (!in_array($key, self::DOCUMENT_KEYS, true)) {
                continue; // strip unknown keys
            }

            match ($key) {
                'invoicePeriod' => $result['invoicePeriod'] = $this->validateInvoicePeriod($value),
                'delivery' => $result['delivery'] = $this->validateDelivery($value),
                'allowanceCharges' => $result['allowanceCharges'] = $this->validateDocumentAllowanceCharges($value),
                'prepaidAmount' => $result['prepaidAmount'] = $this->validatePrepaidAmount($value),
                'additionalDocumentReferences' => $result['additionalDocumentReferences'] = $this->validateAdditionalDocumentReferences($value),
            };
        }

        return $result === [] ? null : $result;
    }

    /**
     * Validate and sanitize line-level ublExtensions.
     * Returns cleaned array or null. Throws on invalid values.
     */
    public function validateLineExtensions(?array $data): ?array
    {
        if ($data === null || $data === []) {
            return null;
        }

        $result = [];

        foreach ($data as $key => $value) {
            if (!in_array($key, self::LINE_KEYS, true)) {
                continue; // strip unknown keys
            }

            match ($key) {
                'invoicePeriod' => $result['invoicePeriod'] = $this->validateInvoicePeriod($value),
                'allowanceCharges' => $result['allowanceCharges'] = $this->validateLineAllowanceCharges($value),
                'additionalItemProperties' => $result['additionalItemProperties'] = $this->validateAdditionalItemProperties($value),
                'originCountry' => $result['originCountry'] = $this->validateOriginCountry($value),
            };
        }

        return $result === [] ? null : $result;
    }

    private function validateInvoicePeriod(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException('ublExtensions.invoicePeriod must be an object.');
        }

        $result = [];

        if (isset($value['startDate'])) {
            $this->assertDateFormat($value['startDate'], 'invoicePeriod.startDate');
            $result['startDate'] = $value['startDate'];
        }

        if (isset($value['endDate'])) {
            $this->assertDateFormat($value['endDate'], 'invoicePeriod.endDate');
            $result['endDate'] = $value['endDate'];
        }

        if (isset($value['descriptionCode'])) {
            $result['descriptionCode'] = (string) $value['descriptionCode'];
        }

        if ($result === []) {
            throw new \InvalidArgumentException('ublExtensions.invoicePeriod must have at least startDate or endDate.');
        }

        return $result;
    }

    private function validateDelivery(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException('ublExtensions.delivery must be an object.');
        }

        $result = [];

        if (isset($value['actualDeliveryDate'])) {
            $this->assertDateFormat($value['actualDeliveryDate'], 'delivery.actualDeliveryDate');
            $result['actualDeliveryDate'] = $value['actualDeliveryDate'];
        }

        if (isset($value['deliveryAddress'])) {
            if (!is_array($value['deliveryAddress'])) {
                throw new \InvalidArgumentException('ublExtensions.delivery.deliveryAddress must be an object.');
            }
            $addr = [];
            foreach (['streetName', 'cityName', 'countrySubentity', 'postalZone'] as $field) {
                if (isset($value['deliveryAddress'][$field])) {
                    $addr[$field] = (string) $value['deliveryAddress'][$field];
                }
            }
            if (isset($value['deliveryAddress']['countryCode'])) {
                $cc = strtoupper((string) $value['deliveryAddress']['countryCode']);
                if (!in_array($cc, self::VALID_COUNTRY_CODES, true)) {
                    throw new \InvalidArgumentException("ublExtensions.delivery.deliveryAddress.countryCode: invalid country code '$cc'.");
                }
                $addr['countryCode'] = $cc;
            }
            if ($addr !== []) {
                $result['deliveryAddress'] = $addr;
            }
        }

        if ($result === []) {
            throw new \InvalidArgumentException('ublExtensions.delivery must have actualDeliveryDate or deliveryAddress.');
        }

        return $result;
    }

    private function validateDocumentAllowanceCharges(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException('ublExtensions.allowanceCharges must be an array.');
        }

        if (count($value) > 20) {
            throw new \InvalidArgumentException('ublExtensions.allowanceCharges: max 20 entries allowed.');
        }

        $result = [];
        foreach ($value as $i => $item) {
            if (!is_array($item)) {
                throw new \InvalidArgumentException("ublExtensions.allowanceCharges[$i] must be an object.");
            }
            $result[] = $this->validateAllowanceCharge($item, "allowanceCharges[$i]", true);
        }

        return $result;
    }

    private function validateLineAllowanceCharges(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException('ublExtensions.allowanceCharges must be an array.');
        }

        if (count($value) > 10) {
            throw new \InvalidArgumentException('ublExtensions.allowanceCharges: max 10 entries allowed.');
        }

        $result = [];
        foreach ($value as $i => $item) {
            if (!is_array($item)) {
                throw new \InvalidArgumentException("ublExtensions.allowanceCharges[$i] must be an object.");
            }
            $result[] = $this->validateAllowanceCharge($item, "allowanceCharges[$i]", false);
        }

        return $result;
    }

    private function validateAllowanceCharge(array $item, string $path, bool $requireTaxCategory): array
    {
        if (!isset($item['chargeIndicator']) || !is_bool($item['chargeIndicator'])) {
            throw new \InvalidArgumentException("ublExtensions.$path.chargeIndicator is required and must be boolean.");
        }

        if (!isset($item['amount'])) {
            throw new \InvalidArgumentException("ublExtensions.$path.amount is required.");
        }
        $this->assertNumericPositive($item['amount'], "$path.amount");

        $result = [
            'chargeIndicator' => $item['chargeIndicator'],
            'amount' => $this->formatDecimal($item['amount']),
        ];

        if (isset($item['reasonCode'])) {
            $result['reasonCode'] = (string) $item['reasonCode'];
        }
        if (isset($item['reason'])) {
            $result['reason'] = (string) $item['reason'];
        }
        if (isset($item['baseAmount'])) {
            $this->assertNumericPositive($item['baseAmount'], "$path.baseAmount");
            $result['baseAmount'] = $this->formatDecimal($item['baseAmount']);
        }
        if (isset($item['multiplierFactorNumeric'])) {
            if (!is_numeric($item['multiplierFactorNumeric'])) {
                throw new \InvalidArgumentException("ublExtensions.$path.multiplierFactorNumeric must be numeric.");
            }
            $result['multiplierFactorNumeric'] = $this->formatDecimal($item['multiplierFactorNumeric']);
        }

        if ($requireTaxCategory) {
            if (!isset($item['taxCategoryCode'])) {
                throw new \InvalidArgumentException("ublExtensions.$path.taxCategoryCode is required for document-level allowance/charge.");
            }
            if (!in_array($item['taxCategoryCode'], self::VALID_TAX_CATEGORY_CODES, true)) {
                throw new \InvalidArgumentException("ublExtensions.$path.taxCategoryCode: invalid value '{$item['taxCategoryCode']}'.");
            }
            $result['taxCategoryCode'] = $item['taxCategoryCode'];

            if (!isset($item['taxRate'])) {
                throw new \InvalidArgumentException("ublExtensions.$path.taxRate is required for document-level allowance/charge.");
            }
            if (!is_numeric($item['taxRate'])) {
                throw new \InvalidArgumentException("ublExtensions.$path.taxRate must be numeric.");
            }
            $result['taxRate'] = $this->formatDecimal($item['taxRate']);
        }

        return $result;
    }

    private function validatePrepaidAmount(mixed $value): string
    {
        if (!is_numeric($value) || (float) $value < 0) {
            throw new \InvalidArgumentException('ublExtensions.prepaidAmount must be a numeric string >= 0.');
        }

        return $this->formatDecimal($value);
    }

    private function validateAdditionalDocumentReferences(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException('ublExtensions.additionalDocumentReferences must be an array.');
        }

        if (count($value) > 10) {
            throw new \InvalidArgumentException('ublExtensions.additionalDocumentReferences: max 10 entries allowed.');
        }

        $result = [];
        foreach ($value as $i => $item) {
            if (!is_array($item)) {
                throw new \InvalidArgumentException("ublExtensions.additionalDocumentReferences[$i] must be an object.");
            }

            if (empty($item['id'])) {
                throw new \InvalidArgumentException("ublExtensions.additionalDocumentReferences[$i].id is required.");
            }
            if (mb_strlen($item['id']) > 200) {
                throw new \InvalidArgumentException("ublExtensions.additionalDocumentReferences[$i].id: max 200 characters.");
            }

            $ref = ['id' => (string) $item['id']];

            if (isset($item['documentTypeCode'])) {
                $ref['documentTypeCode'] = (string) $item['documentTypeCode'];
            }
            if (isset($item['documentDescription'])) {
                $ref['documentDescription'] = (string) $item['documentDescription'];
            }

            $result[] = $ref;
        }

        return $result;
    }

    private function validateAdditionalItemProperties(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException('ublExtensions.additionalItemProperties must be an array.');
        }

        if (count($value) > 20) {
            throw new \InvalidArgumentException('ublExtensions.additionalItemProperties: max 20 entries allowed.');
        }

        $result = [];
        foreach ($value as $i => $item) {
            if (!is_array($item)) {
                throw new \InvalidArgumentException("ublExtensions.additionalItemProperties[$i] must be an object.");
            }

            if (empty($item['name'])) {
                throw new \InvalidArgumentException("ublExtensions.additionalItemProperties[$i].name is required.");
            }
            if (mb_strlen($item['name']) > 50) {
                throw new \InvalidArgumentException("ublExtensions.additionalItemProperties[$i].name: max 50 characters.");
            }

            if (!isset($item['value'])) {
                throw new \InvalidArgumentException("ublExtensions.additionalItemProperties[$i].value is required.");
            }
            if (mb_strlen((string) $item['value']) > 100) {
                throw new \InvalidArgumentException("ublExtensions.additionalItemProperties[$i].value: max 100 characters.");
            }

            $result[] = [
                'name' => (string) $item['name'],
                'value' => (string) $item['value'],
            ];
        }

        return $result;
    }

    private function validateOriginCountry(mixed $value): string
    {
        $code = strtoupper(trim((string) $value));

        if (!in_array($code, self::VALID_COUNTRY_CODES, true)) {
            throw new \InvalidArgumentException("ublExtensions.originCountry: invalid country code '$code'.");
        }

        return $code;
    }

    private function assertDateFormat(mixed $value, string $field): void
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw new \InvalidArgumentException("ublExtensions.$field must be a date in YYYY-MM-DD format.");
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException("ublExtensions.$field: invalid date '$value'.");
        }
    }

    private function assertNumericPositive(mixed $value, string $field): void
    {
        if (!is_numeric($value) || (float) $value <= 0) {
            throw new \InvalidArgumentException("ublExtensions.$field must be a positive number.");
        }
    }

    private function formatDecimal(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
