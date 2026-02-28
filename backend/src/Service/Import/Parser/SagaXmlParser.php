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

        foreach ($xml->children() as $element) {
            $row = $this->elementToArray($element);
            if (!empty($row)) {
                yield $row;
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

        foreach ($xml->children() as $element) {
            $row = $this->elementToArray($element);
            if (empty($row)) {
                continue;
            }

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

        return $xml->count();
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
