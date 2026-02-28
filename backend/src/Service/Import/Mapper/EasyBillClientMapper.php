<?php

namespace App\Service\Import\Mapper;

/**
 * Column mapper for client CSV exports produced by EasyBill.
 *
 * EasyBill uses Romanian column headers. The three anchor columns
 * used for confidence detection are:
 *  - "Denumire client"
 *  - "CUI"
 *  - "Reg. Com."
 */
class EasyBillClientMapper extends AbstractClientMapper
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
            'Denumire client'      => 'name',
            'CUI'                  => 'cui',
            'Reg. Com.'            => 'registrationNumber',
            'Adresa'               => 'address',
            'Oras'                 => 'city',
            'Judet'                => 'county',
            'Tara'                 => 'country',
            'Cod postal'           => 'postalCode',
            'Email'                => 'email',
            'Telefon'              => 'phone',
            'Banca'                => 'bankName',
            'IBAN'                 => 'bankAccount',
            'Persoana de contact'  => 'contactPerson',
            'Cod client'           => 'clientCode',
        ];
    }

    /**
     * @param string[] $headers
     */
    public function detectConfidence(array $headers): float
    {
        $anchors = ['Denumire client', 'CUI', 'Reg. Com.'];

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
