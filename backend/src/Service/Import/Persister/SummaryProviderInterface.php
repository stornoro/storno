<?php

namespace App\Service\Import\Persister;

/**
 * A persister that aggregates rows into documents reports what it built
 * (documents, daily totals) so the import job can show it to the user.
 */
interface SummaryProviderInterface
{
    /**
     * @return array<string, mixed> flat scalars / small lists, stored on the import job
     */
    public function getSummary(): array;
}
