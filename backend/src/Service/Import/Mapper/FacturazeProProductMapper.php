<?php

namespace App\Service\Import\Mapper;

/**
 * Column mapper for product CSV exports produced by FacturarePro.
 *
 * Anchor columns for confidence detection:
 *  - "Produs"
 *  - "Cod"
 *  - "Unitate"
 */
class FacturazeProProductMapper extends AbstractProductMapper
{
    public function getSource(): string
    {
        return 'facturare_pro';
    }

    /**
     * @return array<string, string>
     */
    public function getDefaultMapping(): array
    {
        return [
            'Produs'    => 'name',
            'Cod'       => 'code',
            'Descriere' => 'description',
            'Unitate'   => 'unitOfMeasure',
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
        $anchors = ['Produs', 'Cod', 'Unitate'];

        return $this->ratioFound($anchors, $headers);
    }
}
