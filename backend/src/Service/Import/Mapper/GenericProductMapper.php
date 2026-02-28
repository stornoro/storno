<?php

namespace App\Service\Import\Mapper;

/**
 * Generic fallback mapper for product imports with no platform-specific columns.
 *
 * This mapper provides no default column mapping so the user must configure
 * every mapping manually in the UI. It reports a very low detection confidence
 * (0.1) so that platform-specific mappers always win when their columns match.
 */
class GenericProductMapper extends AbstractProductMapper
{
    public function getSource(): string
    {
        return 'generic';
    }

    /**
     * @return array<string, string>
     */
    public function getDefaultMapping(): array
    {
        return [];
    }

    /**
     * Always returns 0.1 — the generic mapper is the last resort.
     *
     * @param string[] $headers
     */
    public function detectConfidence(array $headers): float
    {
        return 0.1;
    }
}
