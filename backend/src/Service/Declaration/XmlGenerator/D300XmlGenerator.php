<?php

namespace App\Service\Declaration\XmlGenerator;

use App\Entity\TaxDeclaration;
use App\Service\Declaration\DeclarationXmlGeneratorInterface;

/**
 * Generates the D300 (decont de TVA) XML: one flat <declaratie300> element whose attributes
 * are ANAF's row identifiers (R9_1 = base of rd.9, R9_2 = its VAT, …) in whole lei.
 *
 * `data.rows` already uses those attribute names (see D300Populator / D300Layout); anything
 * the user overrode or added by hand is written as it is. The namespace of the current
 * form version is applied by TaxDeclarationManager through DeclarationNamespaceResolver.
 */
class D300XmlGenerator implements DeclarationXmlGeneratorInterface
{
    /** Attributes the form has besides the R-rows; written when present in the data. */
    private const HEADER_ATTRIBUTES = [
        'luna', 'an', 'cui', 'den', 'adresa', 'telefon', 'fax', 'mail', 'banca', 'cont', 'caen',
        'tip_decont', 'pro_rata', 'bifa_interne', 'temei', 'cuiSuccesor', 'depusReprezentant',
        'nume_declar', 'prenume_declar', 'functie_declar', 'bifa_cereale', 'bifa_mob', 'bifa_disp', 'bifa_cons',
        'solicit_ramb', 'nr_evid',
    ];

    public function supportsType(string $type): bool
    {
        return $type === 'd300';
    }

    public function generate(TaxDeclaration $declaration): string
    {
        $data = $declaration->getData() ?? [];
        $company = $declaration->getCompany();
        $rows = $data['rows'] ?? [];

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $root = $dom->createElement('declaratie300');
        $root->setAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');

        $header = [
            'luna' => (string) $declaration->getMonth(),
            'an' => (string) $declaration->getYear(),
            'cui' => (string) $company->getCif(),
            'den' => (string) ($company->getName() ?? ''),
            'adresa' => (string) ($company->getAddress() ?? ''),
            'telefon' => (string) ($company->getPhone() ?? ''),
            'mail' => (string) ($company->getEmail() ?? ''),
            'tip_decont' => $declaration->getPeriodType() === 'quarterly' ? 'T' : 'L',
            'pro_rata' => '0',
            'solicit_ramb' => 'N',
            'bifa_interne' => '0', 'depusReprezentant' => '0', 'temei' => '0',
            'bifa_cereale' => 'N', 'bifa_mob' => 'N', 'bifa_disp' => 'N', 'bifa_cons' => 'N',
        ];
        // Header values kept in the rows (e.g. edited by hand or read from an uploaded XML) win
        foreach (self::HEADER_ATTRIBUTES as $attr) {
            if (isset($rows[$attr]) && $rows[$attr] !== '') {
                $header[$attr] = (string) $rows[$attr];
            }
        }
        // The validator accepts an attribute either absent or non-empty; the four bifa_* boxes only when ticked
        foreach ($header as $attr => $value) {
            if ($value === '' && !in_array($attr, ['den', 'adresa'], true)) {
                continue;
            }
            $root->setAttribute($attr, $value);
        }

        // Row values: whole lei; zero rows are left out (the form treats a missing attribute as 0).
        // totalPlata_A is the form's control sum: the sum of every row value written.
        $controlSum = 0;
        foreach ($rows as $attr => $value) {
            if (!preg_match('/^R\d+(?:_\d+)*$/', (string) $attr)) {
                continue;
            }
            $lei = (int) round((float) $value);
            if ($lei === 0) {
                continue;
            }
            $root->setAttribute((string) $attr, (string) $lei);
            $controlSum += $lei;
        }
        $root->setAttribute('totalPlata_A', (string) $controlSum);

        $dom->appendChild($root);

        return $dom->saveXML();
    }
}
