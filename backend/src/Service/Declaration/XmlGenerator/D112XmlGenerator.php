<?php

namespace App\Service\Declaration\XmlGenerator;

use App\Entity\TaxDeclaration;
use App\Service\Declaration\DeclarationXmlGeneratorInterface;

/**
 * Generates ANAF-compliant D112 XML (Declaratie unica CAS/CASS/impozit salarii).
 *
 * Root: <declaratieUnica> with <angajator> child containing <asigurat> children.
 * Most complex schema — hierarchical with sections angajatorA through angajatorE.
 */
class D112XmlGenerator implements DeclarationXmlGeneratorInterface
{
    public function supportsType(string $type): bool
    {
        return $type === 'd112';
    }

    public function generate(TaxDeclaration $declaration): string
    {
        $data = $declaration->getData();
        $company = $declaration->getCompany();
        $rows = $data['rows'] ?? [];

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $root = $dom->createElement('declaratieUnica');
        $root->setAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $root->setAttribute('luna', (string) $declaration->getMonth());
        $root->setAttribute('an', (string) $declaration->getYear());
        $root->setAttribute('d_rec', '0');

        $dom->appendChild($root);

        // Angajator section
        $angajator = $dom->createElement('angajator');
        $angajator->setAttribute('cui', (string) $company->getCif());
        $angajator->setAttribute('den', $company->getName() ?? '');
        $angajator->setAttribute('adresa', $company->getAddress() ?? '');
        $angajator->setAttribute('telefon', $company->getPhone() ?? '');

        // Write angajator-level attributes from rows (angajatorA, angajatorB, etc.)
        foreach (['angajatorA', 'angajatorB', 'angajatorC', 'angajatorD', 'angajatorE'] as $section) {
            if (isset($rows[$section]) && is_array($rows[$section])) {
                $sectionEl = $dom->createElement($section);
                foreach ($rows[$section] as $key => $value) {
                    $sectionEl->setAttribute($key, (string) $value);
                }
                $angajator->appendChild($sectionEl);
            }
        }

        // Asigurati (insured persons)
        $asigurati = $rows['asigurati'] ?? [];
        foreach ($asigurati as $asigurat) {
            $asigEl = $dom->createElement('asigurat');
            foreach ($asigurat as $key => $value) {
                if (is_array($value)) {
                    continue;
                }
                $asigEl->setAttribute($key, (string) $value);
            }
            $angajator->appendChild($asigEl);
        }

        // Flat angajator attributes
        foreach ($rows as $key => $value) {
            if (in_array($key, ['angajatorA', 'angajatorB', 'angajatorC', 'angajatorD', 'angajatorE', 'asigurati'], true)) {
                continue;
            }
            if (!is_array($value)) {
                $angajator->setAttribute($key, (string) $value);
            }
        }

        $root->appendChild($angajator);

        return $dom->saveXML();
    }
}
