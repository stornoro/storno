<?php

namespace App\Service\Import\Mapper;

/**
 * A mapper whose export has no fixed header set (platform exports get renamed
 * and translated often) builds the suggested mapping from the headers it
 * actually sees instead of returning a static default mapping.
 */
interface HeaderAwareMappingInterface
{
    /**
     * @param string[] $headers Detected column headers of the uploaded file
     * @return array<string, string> sourceColumn => targetField
     */
    public function suggestMapping(array $headers): array;
}
