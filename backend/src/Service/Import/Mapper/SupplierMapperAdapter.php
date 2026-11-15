<?php

namespace App\Service\Import\Mapper;

/**
 * Supplier imports read the same partner files as client imports: every invoicing and
 * accounting program exports its suppliers with the columns it uses for its clients
 * (name, CUI, trade register number, address, bank). So instead of one supplier mapper
 * per program, each client mapper is wrapped: same source, same column names, same
 * CUI / VAT code normalisation, restricted to the fields a supplier has.
 *
 * The orchestrator builds one adapter for every registered client mapper, so a program
 * added for clients is available for suppliers too.
 */
final class SupplierMapperAdapter implements ColumnMapperInterface, HeaderAwareMappingInterface
{
    public const IMPORT_TYPE = 'suppliers';

    /** Target fields a supplier has, with the labels shown in the column mapping step. */
    private const TARGET_FIELDS = [
        'name'               => 'Denumire',
        'cui'                => 'CUI / CIF',
        'registrationNumber' => 'Nr. Reg. Comerț',
        'address'            => 'Adresa',
        'city'               => 'Oraș',
        'county'             => 'Județ',
        'country'            => 'Țara',
        'postalCode'         => 'Cod poștal',
        'email'              => 'Email',
        'phone'              => 'Telefon',
        'bankName'           => 'Banca',
        'bankAccount'        => 'IBAN',
        'notes'              => 'Observații',
    ];

    public function __construct(private readonly ColumnMapperInterface $clientMapper)
    {
    }

    public function getSource(): string
    {
        return $this->clientMapper->getSource();
    }

    public function getImportType(): string
    {
        return self::IMPORT_TYPE;
    }

    public function getDefaultMapping(): array
    {
        return $this->onlySupplierFields($this->clientMapper->getDefaultMapping());
    }

    /**
     * The program's own column names first; the headers it does not know are then matched by
     * their usual names ("Denumire furnizor", "CIF", "Cod fiscal", "Localitate", "Cont bancar"…),
     * because a supplier export often renames a column or two against the client export — and a
     * plain spreadsheet (source "generic") has no program-specific names at all.
     */
    public function suggestMapping(array $headers): array
    {
        $known = $this->clientMapper instanceof HeaderAwareMappingInterface
            ? $this->clientMapper->suggestMapping($headers)
            : $this->clientMapper->getDefaultMapping();
        $known = $this->onlySupplierFields($known);

        $mapping = PartnerHeaderAliases::suggest($headers, $known, array_keys(self::TARGET_FIELDS));

        // nothing recognised in the file: fall back to the program's static mapping
        return $mapping !== [] ? $mapping : $known;
    }

    public function getRequiredFields(): array
    {
        return ['name'];
    }

    public function getTargetFields(): array
    {
        return self::TARGET_FIELDS;
    }

    public function mapRow(array $row, array $columnMapping): array
    {
        $mapped = $this->clientMapper->mapRow($row, $this->onlySupplierFields($columnMapping));
        // client-only notions the client mapper derives on its own
        unset($mapped['type'], $mapped['cnp'], $mapped['defaultPaymentTermDays']);

        return $mapped;
    }

    public function detectConfidence(array $headers): float
    {
        return $this->clientMapper->detectConfidence($headers);
    }

    /**
     * @param array<string, string|null> $mapping sourceColumn => targetField
     * @return array<string, string>
     */
    private function onlySupplierFields(array $mapping): array
    {
        return array_filter(
            $mapping,
            static fn ($target) => is_string($target) && isset(self::TARGET_FIELDS[$target]),
        );
    }
}
