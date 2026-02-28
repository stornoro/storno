<?php

namespace App\Service\Import\Mapper;

interface ColumnMapperInterface
{
    /**
     * Source platform identifier (e.g., 'smartbill', 'saga', 'generic').
     */
    public function getSource(): string;

    /**
     * Import type this mapper handles (e.g., 'clients', 'products').
     */
    public function getImportType(): string;

    /**
     * Default column mapping: source column name => target entity field.
     *
     * @return array<string, string>
     */
    public function getDefaultMapping(): array;

    /**
     * Required target fields that must be mapped.
     *
     * @return string[]
     */
    public function getRequiredFields(): array;

    /**
     * Available target fields with labels for the UI.
     *
     * @return array<string, string> fieldName => human label
     */
    public function getTargetFields(): array;

    /**
     * Map a raw row using the provided column mapping.
     *
     * @param array<string, string> $row           Raw row data keyed by source column
     * @param array<string, string> $columnMapping  sourceColumn => targetField
     * @return array<string, mixed> Mapped data keyed by target field
     */
    public function mapRow(array $row, array $columnMapping): array;

    /**
     * Detect confidence that these headers match this mapper's expected format.
     *
     * @param string[] $headers
     * @return float 0.0 to 1.0
     */
    public function detectConfidence(array $headers): float;
}
