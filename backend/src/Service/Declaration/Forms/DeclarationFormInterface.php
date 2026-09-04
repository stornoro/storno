<?php

declare(strict_types=1);

namespace App\Service\Declaration\Forms;

/**
 * A declaration form Storno can build from structured input: the machine-readable
 * specification an AI assistant reads (fields, codes, rules, example) and the
 * builder that turns the input into the ANAF XML plus Storno's own rule checks.
 */
interface DeclarationFormInterface
{
    /** ANAF form code, e.g. C168 */
    public function type(): string;

    /** @return array{type: string, title: string, titleEn: string, description: string, descriptionEn: string} */
    public function summary(): array;

    /** @return array<string, mixed> full specification: input schema, XML mapping, rules, example */
    public function spec(): array;

    /**
     * @param array<string, mixed> $input
     */
    public function build(array $input): FormBuildResult;
}
