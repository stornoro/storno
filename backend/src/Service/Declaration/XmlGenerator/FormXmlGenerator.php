<?php

declare(strict_types=1);

namespace App\Service\Declaration\XmlGenerator;

use App\Entity\TaxDeclaration;
use App\Service\Declaration\DeclarationXmlGeneratorInterface;
use App\Service\Declaration\Forms\DeclarationFormRegistry;

/**
 * Declarations filed from Storno whose XML is written by the public form builders
 * (C168 rental contracts, D212 rent income): the declaration's `data.input` is the
 * form input, and Storno's own rules run before ANAF's validator sees the file.
 */
final class FormXmlGenerator implements DeclarationXmlGeneratorInterface
{
    public function __construct(private readonly DeclarationFormRegistry $forms)
    {
    }

    public function supportsType(string $type): bool
    {
        return $this->forms->get($type) !== null;
    }

    public function generate(TaxDeclaration $declaration): string
    {
        $form = $this->forms->get($declaration->getType()->value);
        if ($form === null) {
            throw new \InvalidArgumentException('No form builder for ' . $declaration->getType()->value);
        }
        $input = $declaration->getData()['input'] ?? null;
        if (!is_array($input) || $input === []) {
            throw new \InvalidArgumentException(sprintf('Declarația %s nu are date: completează câmpurile formularului (data.input) înainte de validare.', strtoupper($form->type())));
        }
        $result = $form->build($input);
        if ($result->hasErrors()) {
            $errors = array_values(array_filter($result->issues, fn ($i) => $i['level'] === 'error'));
            throw new \InvalidArgumentException('Datele declarației au erori: ' . implode(' | ', array_map(fn ($i) => sprintf('[%s] %s: %s', $i['code'], $i['field'], $i['message']), array_slice($errors, 0, 8))));
        }

        return $result->xml;
    }
}
