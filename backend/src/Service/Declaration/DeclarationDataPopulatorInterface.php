<?php

namespace App\Service\Declaration;

use App\Entity\Company;

interface DeclarationDataPopulatorInterface
{
    public function populate(Company $company, int $year, int $month, string $periodType): array;

    public function supportsType(string $type): bool;
}
