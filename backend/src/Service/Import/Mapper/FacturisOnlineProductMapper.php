<?php

namespace App\Service\Import\Mapper;

/**
 * Column mapper for product CSV exports produced by FacturisOnline.
 *
 * FacturisOnline uses verbose, qualifier-suffixed Romanian column headers.
 * Anchor columns for confidence detection:
 *  - "Denumire produs"  (full two-word product name header)
 *  - "Cod produs"       (full two-word product code header)
 *  - "Procent TVA"      (VAT rate — "Procent" is unique to FacturisOnline)
 *
 * The "Procent TVA" header is the most reliable discriminator because no
 * other supported platform uses the word "Procent" for the VAT column.
 * The "Pret vanzare" header (sale price) also distinguishes this platform
 * from FGO which uses "Pret" alone.
 */
class FacturisOnlineProductMapper extends AbstractProductMapper
{
    public function getSource(): string
    {
        return 'facturis_online';
    }

    /**
     * @return array<string, string>
     */
    public function getDefaultMapping(): array
    {
        return [
            'Denumire produs' => 'name',
            'Cod produs'      => 'code',
            'Unitate masura'  => 'unitOfMeasure',
            'Pret vanzare'    => 'defaultPrice',
            'Moneda'          => 'currency',
            'Procent TVA'     => 'vatRate',
        ];
    }

    /**
     * Returns ratio of FacturisOnline-specific product headers found in the file.
     *
     * @param string[] $headers
     */
    public function detectConfidence(array $headers): float
    {
        $anchors = ['Denumire produs', 'Cod produs', 'Procent TVA'];

        return $this->ratioFound($anchors, $headers);
    }
}
