<?php

namespace App\Service\Import\Mapper;

/**
 * Column mapper for product CSV exports produced by Oblio.
 *
 * Oblio uses Romanian column headers. Anchor columns for confidence detection:
 *  - "Nume"        (product name — Oblio uses "Nume" not "Denumire")
 *  - "Cod"         (product code)
 *  - "Pret unitar" (unit price — distinctive two-word form)
 *  - "TVA (%)"     (VAT rate — parenthesised percent symbol)
 *
 * "UM" is the short unit-of-measure header shared with SAGA but the
 * combination with "Pret unitar" and "TVA (%)" uniquely identifies Oblio.
 */
class OblioProductMapper extends AbstractProductMapper
{
    public function getSource(): string
    {
        return 'oblio';
    }

    /**
     * @return array<string, string>
     */
    public function getDefaultMapping(): array
    {
        return [
            'Nume'        => 'name',
            'Cod'         => 'code',
            'Descriere'   => 'description',
            'UM'          => 'unitOfMeasure',
            'Pret unitar' => 'defaultPrice',
            'Moneda'      => 'currency',
            'TVA (%)'     => 'vatRate',
        ];
    }

    /**
     * Returns ratio of Oblio-specific product headers found in the file.
     *
     * @param string[] $headers
     */
    public function detectConfidence(array $headers): float
    {
        $anchors = ['Nume', 'Cod', 'Pret unitar', 'TVA (%)'];

        return $this->ratioFound($anchors, $headers);
    }
}
