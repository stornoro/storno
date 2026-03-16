<?php

namespace App\Service\Declaration\XmlGenerator;

use App\Entity\TaxDeclaration;
use App\Service\Declaration\DeclarationXmlGeneratorInterface;

/**
 * Generates ANAF-compliant D300 XML (Decont TVA) per XSD d300_v12.
 *
 * Root element: <declaratie300> — flat element with all data as attributes.
 * Attributes: luna, an, cui, den, adresa, caen, tip_decont, pro_rata, R1_1...R42_2
 */
class D300XmlGenerator implements DeclarationXmlGeneratorInterface
{
    public function supportsType(string $type): bool
    {
        return $type === 'd300';
    }

    public function generate(TaxDeclaration $declaration): string
    {
        $data = $declaration->getData();
        $company = $declaration->getCompany();
        $rows = $data['rows'] ?? [];

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $root = $dom->createElement('declaratie300');
        $root->setAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $root->setAttribute('luna', (string) $declaration->getMonth());
        $root->setAttribute('an', (string) $declaration->getYear());
        $root->setAttribute('d_rec', '0');
        $root->setAttribute('cui', (string) $company->getCif());
        $root->setAttribute('den', $company->getName() ?? '');
        $root->setAttribute('adresa', $company->getAddress() ?? '');
        $root->setAttribute('telefon', $company->getPhone() ?? '');
        $root->setAttribute('mail', $company->getEmail() ?? '');
        $root->setAttribute('tip_decont', $declaration->getPeriodType() === 'quarterly' ? 'T' : 'L');
        $root->setAttribute('pro_rata', '0');

        // Write all row values as attributes (R1_1, R1_2, R2_1, R2_2, etc.)
        foreach ($rows as $key => $value) {
            $root->setAttribute($key, (string) $value);
        }

        // Ensure totals are set
        $root->setAttribute('R13_2', $data['totals']['collected'] ?? $rows['R13_2'] ?? '0');
        $root->setAttribute('R30_2', $data['totals']['deductible'] ?? $rows['R30_2'] ?? '0');

        $netVat = $data['totals']['net'] ?? '0';
        if (bccomp($netVat, '0', 2) >= 0) {
            $root->setAttribute('R37_2', $netVat); // TVA de plata
            $root->setAttribute('R38_2', '0');
        } else {
            $root->setAttribute('R37_2', '0');
            $root->setAttribute('R38_2', bcmul($netVat, '-1', 2)); // TVA de recuperat
        }

        $dom->appendChild($root);

        return $dom->saveXML();
    }
}
