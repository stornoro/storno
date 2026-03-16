<?php

namespace App\Event\Declaration;

use App\Entity\TaxDeclaration;
use Symfony\Contracts\EventDispatcher\Event;

class DeclarationCreatedEvent extends Event
{
    public const NAME = 'declaration.created';

    public function __construct(
        private readonly TaxDeclaration $declaration,
    ) {
    }

    public function getDeclaration(): TaxDeclaration
    {
        return $this->declaration;
    }
}
