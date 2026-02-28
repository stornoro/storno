<?php

namespace App\Service\EInvoice\Poland;

/**
 * Parses KSeF FA(2) XML documents.
 */
class KsefParser
{
    /**
     * Parse a KSeF FA(2) XML string into a structured array.
     */
    public function parse(string $xml): array
    {
        $dom = new \DOMDocument();
        if (!$dom->loadXML($xml)) {
            throw new \RuntimeException('Failed to parse KSeF FA(2) XML.');
        }

        $root = $dom->documentElement;

        return [
            'header' => $this->parseNaglowek($root),
            'seller' => $this->parsePodmiot($root, 'Podmiot1'),
            'buyer' => $this->parsePodmiot($root, 'Podmiot2'),
            'invoice' => $this->parseFa($root),
        ];
    }

    private function parseNaglowek(\DOMElement $root): array
    {
        $naglowek = $this->getFirstChild($root, 'Naglowek');
        if ($naglowek === null) {
            return [];
        }

        return [
            'kodFormularza' => $this->getVal($naglowek, 'KodFormularza'),
            'wariantFormularza' => $this->getVal($naglowek, 'WariantFormularza'),
            'dataWytworzeniaFa' => $this->getVal($naglowek, 'DataWytworzeniaFa'),
            'systemInfo' => $this->getVal($naglowek, 'SystemInfo'),
        ];
    }

    private function parsePodmiot(\DOMElement $root, string $tagName): array
    {
        $podmiot = $this->getFirstChild($root, $tagName);
        if ($podmiot === null) {
            return [];
        }

        $daneId = $this->getFirstChild($podmiot, 'DaneIdentyfikacyjne');
        $adres = $this->getFirstChild($podmiot, 'Adres');

        return [
            'nip' => $this->getVal($daneId, 'NIP'),
            'nazwa' => $this->getVal($daneId, 'Nazwa'),
            'kodKraju' => $this->getVal($adres, 'KodKraju'),
            'adresL1' => $this->getVal($adres, 'AdresL1'),
            'adresL2' => $this->getVal($adres, 'AdresL2'),
        ];
    }

    private function parseFa(\DOMElement $root): array
    {
        $fa = $this->getFirstChild($root, 'Fa');
        if ($fa === null) {
            return [];
        }

        $result = [
            'rodzajFaktury' => $this->getVal($fa, 'RodzajFaktury'),
            'dataWystawienia' => $this->getVal($fa, 'P_1'),
            'numerFaktury' => $this->getVal($fa, 'P_2'),
            'terminPlatnosci' => $this->getVal($fa, 'TerminPlatnosci'),
            'kodWaluty' => $this->getVal($fa, 'KodWaluty'),
            'lines' => [],
        ];

        // Parse lines
        $faWiersze = $this->getFirstChild($fa, 'FaWiersz');
        if ($faWiersze !== null) {
            foreach ($faWiersze->childNodes as $child) {
                if ($child instanceof \DOMElement && $child->localName === 'FaWierszCtrl') {
                    $result['lines'][] = [
                        'nrWiersza' => $this->getVal($child, 'NrWierszaFa'),
                        'opis' => $this->getVal($child, 'P_7'),
                        'jm' => $this->getVal($child, 'P_8A'),
                        'ilosc' => $this->getVal($child, 'P_8B'),
                        'cenaJednostkowa' => $this->getVal($child, 'P_9A'),
                        'wartoscNetto' => $this->getVal($child, 'P_11'),
                        'stawkaVat' => $this->getVal($child, 'P_12'),
                    ];
                }
            }
        }

        return $result;
    }

    private function getFirstChild(?\DOMElement $parent, string $tagName): ?\DOMElement
    {
        if ($parent === null) {
            return null;
        }

        foreach ($parent->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->localName === $tagName) {
                return $child;
            }
        }

        return null;
    }

    private function getVal(?\DOMElement $parent, string $tagName): ?string
    {
        $child = $this->getFirstChild($parent, $tagName);
        return $child?->textContent ?: null;
    }
}
