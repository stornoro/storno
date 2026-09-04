<?php

declare(strict_types=1);

namespace App\Service\Declaration\Forms;

final class DeclarationFormRegistry
{
    /** @var array<string, DeclarationFormInterface> */
    private array $forms = [];

    /** @param iterable<DeclarationFormInterface> $forms */
    public function __construct(iterable $forms)
    {
        foreach ($forms as $form) {
            $this->forms[strtoupper($form->type())] = $form;
        }
    }

    public function get(string $type): ?DeclarationFormInterface
    {
        return $this->forms[strtoupper(trim($type))] ?? null;
    }

    /** @return list<array{type: string, title: string, titleEn: string, description: string, descriptionEn: string}> */
    public function catalog(): array
    {
        $out = [];
        foreach ($this->forms as $form) {
            $out[] = $form->summary();
        }

        return $out;
    }
}
