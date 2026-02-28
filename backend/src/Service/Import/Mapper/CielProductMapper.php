<?php

namespace App\Service\Import\Mapper;

/**
 * Column mapper for product CSV exports produced by Ciel.
 *
 * Anchor columns for confidence detection:
 *  - "Denumire produs"
 *  - "Cod"
 *  - "Unitate masura"
 */
class CielProductMapper extends AbstractProductMapper
{
    public function getSource(): string
    {
        return 'ciel';
    }

    /**
     * @return array<string, string>
     */
    public function getDefaultMapping(): array
    {
        return [
            'Denumire produs'  => 'name',
            'Cod'              => 'code',
            'Descriere'        => 'description',
            'Unitate masura'   => 'unitOfMeasure',
            'Pret'             => 'defaultPrice',
            'Moneda'           => 'currency',
            'Cota TVA'         => 'vatRate',
            'Tip'              => 'isService',
        ];
    }

    /**
     * @param string[] $headers
     */
    public function detectConfidence(array $headers): float
    {
        $anchors = ['Denumire produs', 'Cod', 'Unitate masura'];

        return $this->ratioFound($anchors, $headers);
    }
}
