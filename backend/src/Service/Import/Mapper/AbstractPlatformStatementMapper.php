<?php

namespace App\Service\Import\Mapper;

/**
 * Base mapper for the statements a platform (ride-hailing, food delivery)
 * exports to its partners: one row per trip / order / transaction with the
 * gross amount collected from the end customer, the platform's commission and
 * what the partner is paid.
 *
 * Every row is normalised into the same shape (import type `platform_sales`);
 * the PlatformSalesPersister groups the rows (`groupBy` = week | day | order)
 * and builds one sales invoice to the platform plus one commission purchase
 * invoice from the platform per group.
 *
 * Platform exports get renamed and translated often, so the mapping is built
 * from header aliases (case- and accent-insensitive); anything unusual is
 * fixed in the mapping step of the wizard.
 */
abstract class AbstractPlatformStatementMapper implements ColumnMapperInterface, HeaderAwareMappingInterface, TemplateAwareMapperInterface
{
    public const IMPORT_TYPE = 'platform_sales';

    public function getImportType(): string
    {
        return self::IMPORT_TYPE;
    }

    /**
     * Platform identity used for the documents when the user does not override
     * it with the `platformName` / `platformCif` / `platformCountry` options.
     *
     * @return array{name: string, country: string, cif: string|null}
     */
    abstract public function getPlatformDefaults(): array;

    /**
     * Header aliases per target field, first alias = canonical (template) header.
     *
     * @return array<string, string[]>
     */
    abstract protected function getHeaderAliases(): array;

    /**
     * Target fields whose presence decides that a file belongs to this platform.
     *
     * @return string[]
     */
    protected function getDetectionFields(): array
    {
        return ['externalId', 'date', 'gross', 'commission'];
    }

    public function getRequiredFields(): array
    {
        return ['date', 'gross'];
    }

    /**
     * @return array<string, string>
     */
    public function getTargetFields(): array
    {
        return [
            'externalId'    => 'ID cursă / comandă',
            'date'          => 'Data',
            'description'   => 'Descriere',
            'counterparty'  => 'Șofer / client final',
            'gross'         => 'Valoare brută (încasat de la client)',
            'tips'          => 'Bacșiș',
            'tolls'         => 'Taxe de drum / alte sume rambursate',
            'vat'           => 'TVA vânzare',
            'commission'    => 'Comision platformă',
            'commissionVat' => 'TVA comision',
            'payout'        => 'Sumă netă plătită',
            'currency'      => 'Monedă',
            'status'        => 'Status',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function getDefaultMapping(): array
    {
        $mapping = [];
        foreach ($this->getHeaderAliases() as $target => $aliases) {
            $mapping[$aliases[0]] = $target;
        }

        return $mapping;
    }

    /**
     * @param string[] $headers
     * @return array<string, string>
     */
    public function suggestMapping(array $headers): array
    {
        $normalizedAliases = [];
        foreach ($this->getHeaderAliases() as $target => $aliases) {
            foreach ($aliases as $alias) {
                $normalizedAliases[self::normalizeHeader($alias)] = $target;
            }
        }

        $mapping = [];
        $used = [];
        foreach ($headers as $header) {
            $target = $normalizedAliases[self::normalizeHeader($header)] ?? null;
            if ($target !== null && !isset($used[$target])) {
                $mapping[$header] = $target;
                $used[$target] = true;
            }
        }

        return $mapping;
    }

    /**
     * @param string[] $headers
     */
    public function detectConfidence(array $headers): float
    {
        $found = array_values($this->suggestMapping($headers));
        $anchors = $this->getDetectionFields();
        $hits = count(array_intersect($anchors, $found));

        return $anchors === [] ? 0.0 : round($hits / count($anchors), 2);
    }

    /**
     * @param array<string, string> $row
     * @param array<string, string> $columnMapping
     * @return array<string, mixed>
     */
    public function mapRow(array $row, array $columnMapping): array
    {
        $result = [];
        foreach ($columnMapping as $sourceCol => $targetField) {
            if ($targetField === '' || $targetField === null) {
                continue;
            }
            $value = $row[$sourceCol] ?? null;
            if ($value === null || trim((string) $value) === '') {
                continue;
            }
            $result[$targetField] = trim((string) $value);
        }

        foreach (['gross', 'tips', 'tolls', 'vat', 'commission', 'commissionVat', 'payout'] as $amountField) {
            if (isset($result[$amountField])) {
                $result[$amountField] = self::normalizeAmount($result[$amountField]);
            }
        }

        // Commissions and fees are often exported as negative numbers (deductions from the payout).
        foreach (['commission', 'commissionVat'] as $field) {
            if (isset($result[$field])) {
                $result[$field] = number_format(abs((float) $result[$field]), 2, '.', '');
            }
        }

        if (isset($result['date'])) {
            $result['date'] = self::normalizeDate($result['date']) ?? $result['date'];
        }

        if (!empty($result['currency'])) {
            $result['currency'] = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $result['currency']) ?: 'RON', 0, 3));
        }

        $result['platform'] = $this->getSource();
        $result['platformDefaults'] = $this->getPlatformDefaults();

        return $this->afterMap($result, $row);
    }

    /**
     * Hook for platform specific fixes after the generic mapping.
     *
     * @param array<string, mixed>  $mapped
     * @param array<string, string> $row
     * @return array<string, mixed>
     */
    protected function afterMap(array $mapped, array $row): array
    {
        return $mapped;
    }

    public static function normalizeHeader(string $header): string
    {
        $h = mb_strtolower(trim($header));
        $h = str_replace(
            ['ă', 'â', 'î', 'ș', 'ş', 'ț', 'ţ', 'é', 'è', 'á', 'ó', 'ú', 'í', 'ñ', 'ü', 'ö'],
            ['a', 'a', 'i', 's', 's', 't', 't', 'e', 'e', 'a', 'o', 'u', 'i', 'n', 'u', 'o'],
            $h,
        );

        return preg_replace('/[^a-z0-9]+/', ' ', $h) ?? $h;
    }

    public static function normalizeAmount(string $value): string
    {
        $value = trim(str_replace([' ', "\xC2\xA0"], '', $value));
        $negative = str_starts_with($value, '-') || str_starts_with($value, '(') && str_ends_with($value, ')');
        // Strip currency symbols / codes: "€12.50", "12,50 lei", "RON 12.50"
        $value = preg_replace('/[^0-9,.\-]/', '', $value) ?? $value;

        if (str_contains($value, ',') && str_contains($value, '.')) {
            // Whichever separator comes last is the decimal one
            if (strrpos($value, ',') > strrpos($value, '.')) {
                $value = str_replace('.', '', $value);
                $value = str_replace(',', '.', $value);
            } else {
                $value = str_replace(',', '', $value);
            }
        } elseif (str_contains($value, ',')) {
            // "1,234" with exactly three digits after a single comma is a thousands separator only
            // when there is no other hint; platform exports use the comma as decimal far more often.
            $value = str_replace(',', '.', $value);
        }

        $value = ltrim($value, '-');
        if (!is_numeric($value)) {
            return '0.00';
        }

        return number_format(($negative ? -1 : 1) * (float) $value, 2, '.', '');
    }

    /**
     * Accepts the date formats seen in platform exports and returns Y-m-d.
     */
    public static function normalizeDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        foreach (['Y-m-d', 'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d\TH:i:s', 'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s.uP', 'Y-m-d\TH:i:s.u\Z', 'Y-m-d\TH:i:s\Z', 'd.m.Y', 'd.m.Y H:i', 'd.m.Y H:i:s', 'd/m/Y', 'd/m/Y H:i', 'd/m/Y H:i:s', 'm/d/Y', 'm/d/Y H:i', 'm/d/Y H:i:s', 'd-m-Y', 'd-m-Y H:i', 'Y/m/d', 'M j, Y', 'M j, Y g:i A', 'j M Y', 'D, M j, Y', 'M j Y'] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat('!' . $format, $value);
            if ($parsed !== false) {
                return $parsed->format('Y-m-d');
            }
        }

        try {
            return (new \DateTimeImmutable($value))->format('Y-m-d');
        } catch (\Exception) {
            return null;
        }
    }
}
