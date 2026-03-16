<?php

namespace App\Service\Declaration\XmlGenerator;

use App\Entity\TaxDeclaration;
use App\Service\Declaration\DeclarationXmlGeneratorInterface;

/**
 * Generates ANAF-compliant D390 XML (Declaratie recapitulativa VIES).
 *
 * Root: <declaratie390> with header attributes.
 * Child <rezumat> element with totals.
 * Repeating <operatie> elements (tip, tara, codO, denO, baza).
 */
class D390XmlGenerator implements DeclarationXmlGeneratorInterface
{
    public function supportsType(string $type): bool
    {
        return in_array($type, ['d390', 'd392', 'd393'], true);
    }

    public function generate(TaxDeclaration $declaration): string
    {
        $data = $declaration->getData();
        $company = $declaration->getCompany();
        $type = $declaration->getType()->value;

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $rootName = 'declaratie' . ltrim($type, 'd');
        $root = $dom->createElement($rootName);
        $root->setAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $root->setAttribute('luna', (string) $declaration->getMonth());
        $root->setAttribute('an', (string) $declaration->getYear());
        $root->setAttribute('d_rec', '0');
        $root->setAttribute('cui', (string) $company->getCif());
        $root->setAttribute('den', $company->getName() ?? '');
        $root->setAttribute('adresa', $company->getAddress() ?? '');
        $root->setAttribute('telefon', $company->getPhone() ?? '');
        $root->setAttribute('mail', $company->getEmail() ?? '');

        $dom->appendChild($root);

        // Operations
        $operations = $data['operations'] ?? [];
        foreach ($operations as $op) {
            $opEl = $dom->createElement('operatie');
            $opEl->setAttribute('tip', $op['tip'] ?? '');
            $opEl->setAttribute('tara', $op['tara'] ?? '');
            $opEl->setAttribute('codO', $op['codO'] ?? '');
            $opEl->setAttribute('denO', $op['denO'] ?? '');
            $opEl->setAttribute('baza', $op['baza'] ?? '0');
            $root->appendChild($opEl);
        }

        // Rezumat (summary)
        $rezumat = $data['rezumat'] ?? [];
        if (!empty($rezumat)) {
            $rezEl = $dom->createElement('rezumat');
            foreach ($rezumat as $key => $value) {
                $rezEl->setAttribute($key, (string) $value);
            }
            $root->appendChild($rezEl);
        }

        return $dom->saveXML();
    }
}
