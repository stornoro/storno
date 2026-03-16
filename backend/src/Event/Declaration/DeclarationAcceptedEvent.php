<?php

namespace App\Event\Declaration;

use App\Entity\TaxDeclaration;
use Symfony\Contracts\EventDispatcher\Event;

class DeclarationAcceptedEvent extends Event
{
    public const NAME = 'declaration.accepted';

    public function __construct(
        private readonly TaxDeclaration $declaration,
    ) {
    }

    public function getDeclaration(): TaxDeclaration
    {
        return $this->declaration;
    }
}
