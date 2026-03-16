<?php

namespace App\Service\Declaration;

use App\Entity\TaxDeclaration;

interface DeclarationXmlGeneratorInterface
{
    public function generate(TaxDeclaration $declaration): string;

    public function supportsType(string $type): bool;
}
