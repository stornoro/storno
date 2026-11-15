<?php

namespace App\Service\Import\Parser;

class SagaXmlParser implements FileParserInterface
{
    public function supports(string $fileFormat): bool
    {
        return $fileFormat === 'saga_xml';
    }

    public function parse(string $filePath): \Generator
    {
        $xml = simplexml_load_file($filePath);
        if ($xml === false) {
            throw new \RuntimeException('Failed to parse XML file');
        }

        yield from $this->rows($xml);
    }

    /**
     * The rows of the document. A partner or article list is flat: one row per child of the
     * root. An invoice file (<Facturi><Factura><Antet/><Detalii><Continut><Linie/>) gives one
     * row per invoice line, carrying the fields of the invoice header next to the line's own.
     *
     * @return \Generator<int, array<string, string>>
     */
    private function rows(\SimpleXMLElement $xml): \Generator
    {
        $isInvoiceFile = strcasecmp($xml->getName(), 'Facturi') === 0;

        foreach ($xml->children() as $element) {
            if (!$isInvoiceFile || strcasecmp($element->getName(), 'Factura') !== 0) {
                $row = $this->elementToArray($element);
                if (!empty($row)) {
                    yield $row;
                }
                continue;
            }

            $header = $this->elementToArray($element); // FacturaID sits next to Antet
            if (isset($element->Antet)) {
                $header = $this->elementToArray($element->Antet) + $header;
            }
            if (isset($element->Observatii)) {
                $header += $this->elementToArray($element->Observatii);
            }
            if ($header === []) {
                continue;
            }

            $lines = $element->xpath('Detalii/Continut/Linie') ?: [];
            if ($lines === []) {
                yield $header;
                continue;
            }
            foreach ($lines as $line) {
                // a line field named like a header one (Cod) must not replace it
                yield $header + $this->elementToArray($line);
            }
        }
    }

    public function preview(string $filePath, int $maxRows = 20): array
    {
        $xml = simplexml_load_file($filePath);
        if ($xml === false) {
            throw new \RuntimeException('Failed to parse XML file');
        }

        $headers = [];
        $rows = [];
        $count = 0;

        foreach ($this->rows($xml) as $row) {
            // Collect all unique headers
            foreach (array_keys($row) as $key) {
                if (!in_array($key, $headers, true)) {
                    $headers[] = $key;
                }
            }

            $rows[] = $row;
            $count++;
            if ($count >= $maxRows) {
                break;
            }
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    public function countRows(string $filePath): int
    {
        $xml = simplexml_load_file($filePath);
        if ($xml === false) {
            return 0;
        }

        return iterator_count($this->rows($xml));
    }

    private function elementToArray(\SimpleXMLElement $element): array
    {
        $data = [];
        foreach ($element->children() as $child) {
            $name = $child->getName();
            // If the child has sub-children, skip (handles nested invoice lines separately)
            if ($child->count() > 0) {
                continue;
            }
            $data[$name] = trim((string) $child);
        }
        return $data;
    }
}
