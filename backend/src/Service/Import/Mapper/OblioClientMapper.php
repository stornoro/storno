<?php

namespace App\Service\Import\Mapper;

/**
 * Column mapper for client CSV exports produced by Oblio.
 *
 * Oblio uses "Nume" for the client name (instead of "Denumire") and
 * "Nr. Reg. Com." for the registration number (with capital letters and
 * a trailing period — different from SmartBill's "Nr. reg. comert").
 * The three anchor columns used for detection are:
 *  - "Nume"
 *  - "CUI"
 *  - "Nr. Reg. Com."
 */
class OblioClientMapper extends AbstractClientMapper
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
            'Nume'           => 'name',
            'CUI'            => 'cui',
            'Nr. Reg. Com.'  => 'registrationNumber',
            'Adresa'         => 'address',
            'Oras'           => 'city',
            'Judet'          => 'county',
            'Tara'           => 'country',
            'Cod Postal'     => 'postalCode',
            'Email'          => 'email',
            'Telefon'        => 'phone',
            'Banca'          => 'bankName',
            'IBAN'           => 'bankAccount',
            'Contact'        => 'contactPerson',
        ];
    }

    /**
     * Returns ratio of Oblio-specific headers found in the file.
     *
     * @param string[] $headers
     */
    public function detectConfidence(array $headers): float
    {
        $anchors = ['Nume', 'CUI', 'Nr. Reg. Com.'];

        return $this->ratioFound($anchors, $headers);
    }

    /**
     * @param string[] $expected
     * @param string[] $headers
     */
    private function ratioFound(array $expected, array $headers): float
    {
        if ($expected === []) {
            return 0.0;
        }

        $found = 0;
        foreach ($expected as $column) {
            if (in_array($column, $headers, true)) {
                $found++;
            }
        }

        return round($found / count($expected), 2);
    }
}
