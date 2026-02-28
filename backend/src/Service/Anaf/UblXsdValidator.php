<?php

namespace App\Service\Anaf;

use App\DTO\Anaf\ValidationError;
use App\DTO\Anaf\ValidationResult;

class UblXsdValidator
{
    private string $xsdDir;

    public function __construct(string $projectDir)
    {
        $this->xsdDir = $projectDir . '/resources/xsd';
    }

    public function validate(string $xml): ValidationResult
    {
        $dom = new \DOMDocument();

        $previousUseErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();

        if (!$dom->loadXML($xml)) {
            $errors = $this->collectLibxmlErrors();
            libxml_use_internal_errors($previousUseErrors);
            return ValidationResult::invalid($errors);
        }

        $rootName = $dom->documentElement->localName;
        $xsdFile = match ($rootName) {
            'Invoice' => $this->xsdDir . '/maindoc/UBL-Invoice-2.1.xsd',
            'CreditNote' => $this->xsdDir . '/maindoc/UBL-CreditNote-2.1.xsd',
            default => null,
        };

        if ($xsdFile === null) {
            libxml_use_internal_errors($previousUseErrors);
            return ValidationResult::invalid([
                new ValidationError("Tip document XML nerecunoscut: $rootName", 'xsd'),
            ]);
        }

        if (!file_exists($xsdFile)) {
            libxml_use_internal_errors($previousUseErrors);
            return ValidationResult::invalid([
                new ValidationError('Fisierul XSD nu a fost gasit: ' . basename($xsdFile), 'xsd'),
            ]);
        }

        libxml_clear_errors();
        $valid = $dom->schemaValidate($xsdFile);
        $errors = $this->collectLibxmlErrors();

        libxml_use_internal_errors($previousUseErrors);

        if ($valid && empty($errors)) {
            return ValidationResult::valid();
        }

        return ValidationResult::invalid($errors);
    }

    /**
     * @return ValidationError[]
     */
    private function collectLibxmlErrors(): array
    {
        $errors = [];

        foreach (libxml_get_errors() as $error) {
            if ($error->level === LIBXML_ERR_WARNING) {
                continue;
            }

            $errors[] = new ValidationError(
                message: trim($error->message),
                source: 'xsd',
                location: $error->line > 0 ? "line:{$error->line}" : null,
            );
        }

        libxml_clear_errors();

        return $errors;
    }
}
