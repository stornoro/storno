<?php

namespace App\Event\Declaration;

use App\Entity\Company;
use Symfony\Contracts\EventDispatcher\Event;

class DeclarationSyncCompletedEvent extends Event
{
    public const NAME = 'declaration.sync_completed';

    public function __construct(
        private readonly Company $company,
        private readonly array $stats,
    ) {
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function getStats(): array
    {
        return $this->stats;
    }
}
