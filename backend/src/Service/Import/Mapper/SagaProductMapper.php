<?php

namespace App\Service\Import\Mapper;

/**
 * Column mapper for product data exported from SAGA Accounting.
 *
 * SAGA exports product data via XML. The field names used here correspond to
 * the XML element names produced by SAGA. Anchor columns for detection:
 *  - "Denumire"  (product name)
 *  - "Cod"       (product code)
 *  - "UM"        (unit of measure — SAGA uses the short form, not the full phrase)
 *  - "Cota_TVA"  (VAT rate — SAGA uses underscore-separated names)
 *
 * The "Tip" column in SAGA encodes product type as a numeric or string value
 * indicating whether the entry is a service or a physical good. The base
 * mapRow() normalises this value via the boolean detection helper.
 */
class SagaProductMapper extends AbstractProductMapper
{
    public function getSource(): string
    {
        return 'saga';
    }

    /**
     * @return array<string, string>
     */
    public function getDefaultMapping(): array
    {
        return [
            'Denumire'  => 'name',
            'Cod'       => 'code',
            'UM'        => 'unitOfMeasure',
            'Pret'      => 'defaultPrice',
            'Cota_TVA'  => 'vatRate',
            'Tip'       => 'isService',
        ];
    }

    /**
     * Returns ratio of SAGA-specific product headers found in the file.
     *
     * "UM" and "Cota_TVA" are highly distinctive to SAGA and disambiguate it
     * from SmartBill which uses "Unitate de masura" and "Cota TVA" instead.
     *
     * @param string[] $headers
     */
    public function detectConfidence(array $headers): float
    {
        $anchors = ['Denumire', 'Cod', 'UM', 'Cota_TVA'];

        return $this->ratioFound($anchors, $headers);
    }
}
