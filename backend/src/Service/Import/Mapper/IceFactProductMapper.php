<?php

namespace App\Service\Import\Mapper;

/**
 * Column mapper for product CSV exports produced by IceFact.
 *
 * IceFact only supports CSV format.
 *
 * Anchor columns for confidence detection:
 *  - "Denumire"
 *  - "Cod"
 *  - "Pret"
 */
class IceFactProductMapper extends AbstractProductMapper
{
    public function getSource(): string
    {
        return 'icefact';
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
        $anchors = ['Denumire', 'Cod', 'Pret'];

        return $this->ratioFound($anchors, $headers);
    }
}
