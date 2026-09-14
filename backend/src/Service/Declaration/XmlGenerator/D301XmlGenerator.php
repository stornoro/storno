<?php

namespace App\Service\Declaration\XmlGenerator;

use App\Entity\TaxDeclaration;
use App\Service\Declaration\DeclarationXmlGeneratorInterface;

/**
 * Generates the D301 (decont special de TVA) XML: <declaratie301> with the header and the
 * section totals as attributes and one <sectiune> child per row of the payment table.
 *
 * `data.rows` holds the header / totals under ANAF's attribute names (see D301Populator) and
 * `data.sections` the table rows. The totals baza1 … tva5 and the control sum totalPlata_A
 * are recomputed from the sections so a hand-edited table still passes the validator's
 * arithmetic (bazaN = Σ baza of the sections of type N, totalPlata_A = Σ of all totals).
 * The namespace of the form version is applied afterwards by DeclarationNamespaceResolver.
 */
class D301XmlGenerator implements DeclarationXmlGeneratorInterface
{
    /** Attributes of the root element, in the order the XSD declares them (empty optional ones are left out). */
    private const HEADER_ATTRIBUTES = [
        'luna', 'an', 'd_rec', 'mijl_trans', 'temei', 'cif', 'denumire', 'adresa', 'telefon', 'fax', 'email',
        'banca', 'cont', 'pers_inreg', 'nr_evid',
    ];
    private const OPTIONAL_ATTRIBUTES = ['adresa', 'telefon', 'fax', 'email'];
    private const SECTION_ATTRIBUTES = ['tip_operatie', 'nr_doc', 'data_doc', 'val_valuta', 'tip_valuta', 'curs_valutar', 'baza', 'tva'];

    public function supportsType(string $type): bool
    {
        return $type === 'd301';
    }

    public function generate(TaxDeclaration $declaration): string
    {
        $data = $declaration->getData() ?? [];
        $company = $declaration->getCompany();
        $rows = $data['rows'] ?? [];
        $sections = is_array($data['sections'] ?? null) ? $data['sections'] : [];

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $root = $dom->createElement('declaratie301');
        $root->setAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');

        $header = [
            'luna' => (string) $declaration->getMonth(),
            'an' => (string) $declaration->getYear(),
            'd_rec' => '0',
            'mijl_trans' => '0',
            'temei' => '2',
            'cif' => (string) $company->getCif(),
            'denumire' => (string) ($company->getName() ?? ''),
            'adresa' => (string) ($company->getAddress() ?? ''),
            'telefon' => mb_substr((string) ($company->getPhone() ?? ''), 0, 15),
            'fax' => '',
            'email' => (string) ($company->getEmail() ?? ''),
            'banca' => '',
            'cont' => '',
            'pers_inreg' => '1',
            'nr_evid' => '',
        ];
        foreach (array_merge(self::HEADER_ATTRIBUTES, ['nume_declarant', 'prenume_declarant', 'functia_declarant']) as $attr) {
            if (isset($rows[$attr]) && $rows[$attr] !== '') {
                $header[$attr] = (string) $rows[$attr];
            }
        }
        foreach (self::HEADER_ATTRIBUTES as $attr) {
            $value = $header[$attr] ?? '';
            if ($value === '' && in_array($attr, self::OPTIONAL_ATTRIBUTES, true)) {
                continue;
            }
            $root->setAttribute($attr, $value);
        }

        // Totals per operation type from the sections; the control sum is their sum
        $totals = [];
        for ($type = 1; $type <= 5; $type++) {
            $totals['baza' . $type] = 0;
            $totals['tva' . $type] = 0;
        }
        foreach ($sections as $section) {
            $type = (int) ($section['tip_operatie'] ?? 0);
            if ($type < 1 || $type > 5) {
                continue;
            }
            $totals['baza' . $type] += (int) round((float) ($section['baza'] ?? 0));
            $totals['tva' . $type] += (int) round((float) ($section['tva'] ?? 0));
        }
        foreach ($totals as $attr => $value) {
            $root->setAttribute($attr, (string) $value);
        }
        $root->setAttribute('totalPlata_A', (string) array_sum($totals));

        foreach (['nume_declarant', 'prenume_declarant', 'functia_declarant'] as $attr) {
            $root->setAttribute($attr, (string) ($header[$attr] ?? ''));
        }

        foreach ($sections as $section) {
            $type = (int) ($section['tip_operatie'] ?? 0);
            if ($type < 1 || $type > 5) {
                continue;
            }
            $el = $dom->createElement('sectiune');
            foreach (self::SECTION_ATTRIBUTES as $attr) {
                $value = (string) ($section[$attr] ?? '');
                if (in_array($attr, ['baza', 'tva'], true)) {
                    $value = (string) (int) round((float) $value);
                } elseif ($attr === 'val_valuta') {
                    $value = number_format((float) $value, 2, '.', '');
                } elseif ($attr === 'curs_valutar') {
                    $value = number_format((float) ($value === '' ? 1 : $value), 4, '.', '');
                } elseif ($attr === 'tip_valuta') {
                    $value = strtoupper($value ?: 'RON');
                }
                $el->setAttribute($attr, $value);
            }
            $root->appendChild($el);
        }

        $dom->appendChild($root);

        return $dom->saveXML();
    }
}
