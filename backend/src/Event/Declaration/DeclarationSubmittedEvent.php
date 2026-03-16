<?php

namespace App\Event\Declaration;

use App\Entity\TaxDeclaration;
use Symfony\Contracts\EventDispatcher\Event;

class DeclarationSubmittedEvent extends Event
{
    public const NAME = 'declaration.submitted';

    public function __construct(
        private readonly TaxDeclaration $declaration,
    ) {
    }

    public function getDeclaration(): TaxDeclaration
    {
        return $this->declaration;
    }
}
