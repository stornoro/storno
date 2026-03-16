<?php

namespace App\Service\Declaration\XmlGenerator;

use App\Entity\TaxDeclaration;
use App\Service\Declaration\DeclarationXmlGeneratorInterface;

/**
 * Generates ANAF-compliant D101 XML (Impozit pe profit).
 *
 * Root: <declaratie101> — flat, all P1-P53 as attributes.
 */
class D101XmlGenerator implements DeclarationXmlGeneratorInterface
{
    public function supportsType(string $type): bool
    {
        return $type === 'd101';
    }

    public function generate(TaxDeclaration $declaration): string
    {
        $data = $declaration->getData();
        $company = $declaration->getCompany();
        $rows = $data['rows'] ?? [];

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $root = $dom->createElement('declaratie101');
        $root->setAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $root->setAttribute('an', (string) $declaration->getYear());
        $root->setAttribute('d_rec', '0');
        $root->setAttribute('cui', (string) $company->getCif());
        $root->setAttribute('den', $company->getName() ?? '');
        $root->setAttribute('adresa', $company->getAddress() ?? '');
        $root->setAttribute('telefon', $company->getPhone() ?? '');

        // Period type: T = trimestrial, A = anual
        $periodType = $declaration->getPeriodType();
        if ($periodType === 'quarterly') {
            $quarter = (int) ceil($declaration->getMonth() / 3);
            $root->setAttribute('trimestru', (string) $quarter);
        }

        // All P1-P53 row values
        foreach ($rows as $key => $value) {
            $root->setAttribute($key, (string) $value);
        }

        $dom->appendChild($root);

        return $dom->saveXML();
    }
}
