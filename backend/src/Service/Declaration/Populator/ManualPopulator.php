<?php

namespace App\Service\Declaration\Populator;

use App\Entity\Company;
use App\Enum\DeclarationType;
use App\Service\Declaration\DeclarationDataPopulatorInterface;

/**
 * Populator for manual-entry declaration types (D100, D101, D106, D112, D120, D130, D180, D205, D208, D212, D301, D311).
 *
 * Returns an empty rows structure that the user fills via the frontend PATCH endpoint.
 */
class ManualPopulator implements DeclarationDataPopulatorInterface
{
    private const MANUAL_TYPES = [
        'd100', 'd101', 'd106', 'd112', 'd120', 'd130', 'd180',
        'd205', 'd208', 'd212', 'd301', 'd311',
    ];

    public function supportsType(string $type): bool
    {
        return in_array($type, self::MANUAL_TYPES, true);
    }

    public function populate(Company $company, int $year, int $month, string $periodType): array
    {
        return [
            'rows' => [],
        ];
    }
}
