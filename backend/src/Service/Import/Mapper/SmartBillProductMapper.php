<?php

namespace App\Service\Import\Mapper;

/**
 * Column mapper for product CSV exports produced by SmartBill.
 *
 * SmartBill product exports use Romanian column headers. The four anchor
 * columns used for confidence detection are:
 *  - "Denumire"          (product name — note: no qualifier like "client")
 *  - "Cod"               (product code)
 *  - "Unitate de masura" (unit of measure — no diacritics in SmartBill exports)
 *  - "Cota TVA"          (VAT rate)
 *
 * The "Tip" column encodes whether the item is a service (e.g. "Serviciu")
 * vs a good (e.g. "Produs"). The base mapRow() will normalise this value
 * to a boolean via the 'serviciu' truthy string detection.
 */
class SmartBillProductMapper extends AbstractProductMapper
{
    public function getSource(): string
    {
        return 'smartbill';
    }

    /**
     * @return array<string, string>
     */
    public function getDefaultMapping(): array
    {
        return [
            'Denumire'          => 'name',
            'Cod'               => 'code',
            'Descriere'         => 'description',
            'Unitate de masura' => 'unitOfMeasure',
            'Pret'              => 'defaultPrice',
            'Moneda'            => 'currency',
            'Cota TVA'          => 'vatRate',
            'Tip'               => 'isService',
        ];
    }

    /**
     * Returns ratio of SmartBill-specific product headers found in the file.
     *
     * @param string[] $headers
     */
    public function detectConfidence(array $headers): float
    {
        $anchors = ['Denumire', 'Cod', 'Unitate de masura', 'Cota TVA'];

        return $this->ratioFound($anchors, $headers);
    }
}
