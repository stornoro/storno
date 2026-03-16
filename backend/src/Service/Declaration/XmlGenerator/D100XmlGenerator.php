<?php

namespace App\Service\Declaration\XmlGenerator;

use App\Entity\TaxDeclaration;
use App\Service\Declaration\DeclarationXmlGeneratorInterface;

/**
 * Generates ANAF-compliant D100 XML (Obligatii plata buget de stat).
 *
 * Root: <declaratie100> with header attributes.
 * Repeating <obligatie> child elements (cod_oblig, cod_bugetar, suma_plata, etc.)
 */
class D100XmlGenerator implements DeclarationXmlGeneratorInterface
{
    public function supportsType(string $type): bool
    {
        return $type === 'd100';
    }

    public function generate(TaxDeclaration $declaration): string
    {
        $data = $declaration->getData();
        $company = $declaration->getCompany();
        $rows = $data['rows'] ?? [];

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $root = $dom->createElement('declaratie100');
        $root->setAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $root->setAttribute('luna', (string) $declaration->getMonth());
        $root->setAttribute('an', (string) $declaration->getYear());
        $root->setAttribute('d_rec', '0');
        $root->setAttribute('cui', (string) $company->getCif());
        $root->setAttribute('den', $company->getName() ?? '');
        $root->setAttribute('adresa', $company->getAddress() ?? '');
        $root->setAttribute('telefon', $company->getPhone() ?? '');

        $dom->appendChild($root);

        // Obligatii (budget obligations)
        $obligatii = $rows['obligatii'] ?? [];
        foreach ($obligatii as $oblig) {
            $oblEl = $dom->createElement('obligatie');
            foreach ($oblig as $key => $value) {
                $oblEl->setAttribute($key, (string) $value);
            }
            $root->appendChild($oblEl);
        }

        // If rows has flat attributes, also write them
        foreach ($rows as $key => $value) {
            if ($key === 'obligatii') {
                continue;
            }
            $root->setAttribute($key, (string) $value);
        }

        return $dom->saveXML();
    }
}
