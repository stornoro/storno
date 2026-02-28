<?php

namespace App\DTO\Anaf;

final readonly class ValidationResult
{
    /**
     * @param bool              $isValid
     * @param ValidationError[] $errors
     * @param string[]          $warnings
     */
    public function __construct(
        public bool $isValid,
        public array $errors = [],
        public array $warnings = [],
    ) {
    }

    public static function valid(): self
    {
        return new self(isValid: true, errors: [], warnings: []);
    }

    /**
     * @param ValidationError[] $errors
     * @param string[]          $warnings
     */
    public static function invalid(array $errors, array $warnings = []): self
    {
        return new self(isValid: false, errors: $errors, warnings: $warnings);
    }

    public static function merge(self ...$results): self
    {
        $errors = [];
        $warnings = [];

        foreach ($results as $result) {
            $errors = array_merge($errors, $result->errors);
            $warnings = array_merge($warnings, $result->warnings);
        }

        return new self(
            isValid: count($errors) === 0,
            errors: $errors,
            warnings: $warnings,
        );
    }
}
