<?php

namespace App\Service\Import\Mapper;

/**
 * Column mapper for product CSV exports produced by EasyBill.
 *
 * Anchor columns for confidence detection:
 *  - "Denumire"
 *  - "Cod"
 *  - "UM"
 *  - "TVA"
 */
class EasyBillProductMapper extends AbstractProductMapper
{
    public function getSource(): string
    {
        return 'easybill';
    }

    /**
     * @return array<string, string>
     */
    public function getDefaultMapping(): array
    {
        return [
            'Denumire'  => 'name',
            'Cod'       => 'code',
            'Descriere' => 'description',
            'UM'        => 'unitOfMeasure',
            'Pret'      => 'defaultPrice',
            'Moneda'    => 'currency',
            'TVA'       => 'vatRate',
            'Tip'       => 'isService',
        ];
    }

    /**
     * @param string[] $headers
     */
    public function detectConfidence(array $headers): float
    {
        $anchors = ['Denumire', 'Cod', 'UM', 'TVA'];

        return $this->ratioFound($anchors, $headers);
    }
}
