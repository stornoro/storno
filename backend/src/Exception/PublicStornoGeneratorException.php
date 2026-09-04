<?php

namespace App\Exception;

/**
 * Thrown by PublicStornoGenerator when the request payload cannot be turned
 * into a storno invoice. Carries one message per offending field.
 */
final class PublicStornoGeneratorException extends \InvalidArgumentException
{
    /**
     * @param array<string, string> $fieldErrors keyed by dotted field path, e.g. "seller.cif"
     */
    public function __construct(private readonly array $fieldErrors)
    {
        parent::__construct('Datele facturii sunt incomplete sau invalide.');
    }

    /**
     * @return array<string, string>
     */
    public function getFieldErrors(): array
    {
        return $this->fieldErrors;
    }
}
