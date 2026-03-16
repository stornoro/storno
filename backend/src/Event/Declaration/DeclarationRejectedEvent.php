<?php

namespace App\Event\Declaration;

use App\Entity\TaxDeclaration;
use Symfony\Contracts\EventDispatcher\Event;

class DeclarationRejectedEvent extends Event
{
    public const NAME = 'declaration.rejected';

    public function __construct(
        private readonly TaxDeclaration $declaration,
    ) {
    }

    public function getDeclaration(): TaxDeclaration
    {
        return $this->declaration;
    }
}
