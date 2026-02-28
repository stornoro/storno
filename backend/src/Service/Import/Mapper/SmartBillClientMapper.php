<?php

namespace App\Service\Import\Mapper;

/**
 * Column mapper for client CSV exports produced by SmartBill.
 *
 * SmartBill uses Romanian column headers. The three most distinctive columns
 * used for confidence detection are:
 *  - "Denumire client"   (full company name — note the extra word vs other platforms)
 *  - "CIF"              (fiscal code — SmartBill uses "CIF" not "CUI")
 *  - "Nr. reg. comert"  (registration number — SmartBill uses lower-case)
 */
class SmartBillClientMapper extends AbstractClientMapper
{
    public function getSource(): string
    {
        return 'smartbill';
    }

    /**
     * @return array<string, string>
     */
    public function getDefaultMapping(): array
    {
        return [
            'Denumire client'      => 'name',
            'CIF'                  => 'cui',
            'Nr. reg. comert'      => 'registrationNumber',
            'Adresa'               => 'address',
            'Oras'                 => 'city',
            'Judet'                => 'county',
            'Tara'                 => 'country',
            'Cod postal'           => 'postalCode',
            'Email'                => 'email',
            'Telefon'              => 'phone',
            'Banca'                => 'bankName',
            'IBAN'                 => 'bankAccount',
            'Persoana contact'     => 'contactPerson',
            'Cod client'           => 'clientCode',
            'Termen plata (zile)'  => 'defaultPaymentTermDays',
        ];
    }

    /**
     * Returns ratio of SmartBill-specific headers found in the file.
     *
     * The three anchor columns are weighted equally. A full match returns 1.0.
     *
     * @param string[] $headers
     */
    public function detectConfidence(array $headers): float
    {
        $anchors = ['Denumire client', 'CIF', 'Nr. reg. comert'];

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
