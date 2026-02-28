<?php

namespace App\Service\Import\Mapper;

/**
 * Column mapper for product CSV exports produced by Factureaza.
 *
 * Anchor columns for confidence detection:
 *  - "Nume produs"
 *  - "Cod produs"
 *  - "UM"
 */
class FactureazaProductMapper extends AbstractProductMapper
{
    public function getSource(): string
    {
        return 'factureaza';
    }

    /**
     * @return array<string, string>
     */
    public function getDefaultMapping(): array
    {
        return [
            'Nume produs'  => 'name',
            'Cod produs'   => 'code',
            'Descriere'    => 'description',
            'UM'           => 'unitOfMeasure',
            'Pret'         => 'defaultPrice',
            'Moneda'       => 'currency',
            'TVA'          => 'vatRate',
            'Tip'          => 'isService',
        ];
    }

    /**
     * @param string[] $headers
     */
    public function detectConfidence(array $headers): float
    {
        $anchors = ['Nume produs', 'Cod produs', 'UM'];

        return $this->ratioFound($anchors, $headers);
    }
}
