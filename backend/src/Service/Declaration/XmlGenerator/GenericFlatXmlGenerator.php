<?php

namespace App\Service\Declaration\XmlGenerator;

use App\Entity\TaxDeclaration;
use App\Service\Declaration\DeclarationXmlGeneratorInterface;

/**
 * Generic flat XML generator for declaration types that follow the pattern:
 * root element with all data as attributes from $data['rows'].
 *
 * Supports: D106, D120, D130, D180, D205, D208, D212, D301, D311.
 */
class GenericFlatXmlGenerator implements DeclarationXmlGeneratorInterface
{
    private const SUPPORTED_TYPES = [
        'd106', 'd120', 'd130', 'd180', 'd205', 'd208', 'd212', 'd301', 'd311',
    ];

    private const ROOT_ELEMENTS = [
        'd106' => 'declaratie106',
        'd120' => 'declaratie120',
        'd130' => 'declaratie130',
        'd180' => 'declaratie180',
        'd205' => 'declaratie205',
        'd208' => 'declaratie208',
        'd212' => 'declaratie212',
        'd301' => 'declaratie301',
        'd311' => 'declaratie311',
    ];

    public function supportsType(string $type): bool
    {
        return in_array($type, self::SUPPORTED_TYPES, true);
    }

    public function generate(TaxDeclaration $declaration): string
    {
        $data = $declaration->getData();
        $company = $declaration->getCompany();
        $rows = $data['rows'] ?? [];
        $type = $declaration->getType()->value;

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $rootName = self::ROOT_ELEMENTS[$type] ?? 'declaratie';
        $root = $dom->createElement($rootName);
        $root->setAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');

        // Common header attributes
        $periodType = $declaration->getPeriodType();
        if ($periodType === 'annual') {
            $root->setAttribute('an', (string) $declaration->getYear());
        } elseif ($periodType === 'quarterly') {
            $root->setAttribute('an', (string) $declaration->getYear());
            $quarter = (int) ceil($declaration->getMonth() / 3);
            $root->setAttribute('trimestru', (string) $quarter);
        } else {
            $root->setAttribute('luna', (string) $declaration->getMonth());
            $root->setAttribute('an', (string) $declaration->getYear());
        }

        $root->setAttribute('d_rec', '0');
        $root->setAttribute('cui', (string) $company->getCif());
        $root->setAttribute('den', $company->getName() ?? '');
        $root->setAttribute('adresa', $company->getAddress() ?? '');
        $root->setAttribute('telefon', $company->getPhone() ?? '');

        // Write all row values as attributes
        foreach ($rows as $key => $value) {
            if (!is_array($value)) {
                $root->setAttribute($key, (string) $value);
            }
        }

        $dom->appendChild($root);

        return $dom->saveXML();
    }
}
