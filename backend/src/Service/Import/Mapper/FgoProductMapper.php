<?php

namespace App\Service\Import\Mapper;

/**
 * Column mapper for product exports produced by FGO (Facturare Go).
 *
 * FGO product exports use Romanian column headers with some distinctive
 * formatting choices. Anchor columns for confidence detection:
 *  - "Denumire"    (product name — same root as SmartBill/SAGA)
 *  - "Cod produs"  (product code — two-word form, unique to FGO)
 *  - "U.M."        (unit of measure — dot-separated abbreviation)
 *
 * The "Cod produs" and "U.M." combination reliably identifies FGO exports
 * and prevents false-positive matches against SmartBill or SAGA files that
 * use "Cod" and "UM" / "Unitate de masura" respectively.
 *
 * The "Tip" column encodes product type as a string; the base mapRow()
 * normalises it to a boolean via the isService detection rules.
 */
class FgoProductMapper extends AbstractProductMapper
{
    public function getSource(): string
    {
        return 'fgo';
    }

    /**
     * @return array<string, string>
     */
    public function getDefaultMapping(): array
    {
        return [
            'Denumire'    => 'name',
            'Cod produs'  => 'code',
            'U.M.'        => 'unitOfMeasure',
            'Pret'        => 'defaultPrice',
            'TVA'         => 'vatRate',
            'Tip'         => 'isService',
        ];
    }

    /**
     * Returns ratio of FGO-specific product headers found in the file.
     *
     * Three anchors are used; "Cod produs" and "U.M." are highly distinctive.
     *
     * @param string[] $headers
     */
    public function detectConfidence(array $headers): float
    {
        $anchors = ['Denumire', 'Cod produs', 'U.M.'];

        return $this->ratioFound($anchors, $headers);
    }
}
