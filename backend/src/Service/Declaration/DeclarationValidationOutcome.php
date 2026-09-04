<?php

declare(strict_types=1);

namespace App\Service\Declaration;

final class DeclarationValidationOutcome
{
    /**
     * @param list<string> $errors
     * @param list<string> $warnings
     */
    public function __construct(
        public readonly bool $valid,
        public readonly string $type,
        /** The XML as validated, with the namespace ANAF expects (may differ from the input). */
        public readonly string $xml,
        public readonly ?string $namespace,
        public readonly bool $namespaceCorrected,
        public readonly array $errors,
        public readonly array $warnings,
        public readonly int $elapsedMs,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(bool $includeXml = false): array
    {
        $out = [
            'valid' => $this->valid,
            'type' => $this->type,
            'namespace' => $this->namespace,
            'namespaceCorrected' => $this->namespaceCorrected,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'elapsedMs' => $this->elapsedMs,
        ];
        if ($includeXml && $this->namespaceCorrected) {
            $out['xml'] = $this->xml;
        }

        return $out;
    }
}
