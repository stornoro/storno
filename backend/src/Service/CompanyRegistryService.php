<?php

namespace App\Service;

class CompanyRegistryService
{
    private ?\PDO $pdo = null;
    private readonly string $dbPath;
    private readonly string $dataDir;

    public function __construct(string $projectDir)
    {
        $this->dataDir = $projectDir . '/var/data';
        $this->dbPath = $this->dataDir . '/company_registry.sqlite';
    }

    public function isAvailable(): bool
    {
        return file_exists($this->dbPath);
    }

    /**
     * Search companies by CUI or name.
     *
     * @return array<int, array{denumire: string, cod_unic: string, cod_inmatriculare: ?string, adresa: ?string, localitate: ?string, nume_judet: ?string, radiat: bool}>
     */
    public function search(string $query, int $limit = 20): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        $query = trim($query);
        if (mb_strlen($query) < 2) {
            return [];
        }

        $limit = min($limit, 50);

        $pdo = $this->getConnection();

        // Detect CUI search (starts with digits, optionally prefixed by RO)
        $cleaned = preg_replace('/^RO/i', '', $query);
        $isCuiSearch = ctype_digit($cleaned);

        if ($isCuiSearch) {
            return $this->searchByCui($pdo, $cleaned, $limit);
        }

        return $this->searchByName($pdo, $query, $limit);
    }

    private function searchByCui(\PDO $pdo, string $cui, int $limit): array
    {
        // FTS5 prefix search on CUI
        $ftsQuery = '"' . $this->escapeFts($cui) . '"*';

        $stmt = $pdo->prepare('
            SELECT c.denumire, c.cui, c.cod_inmatriculare, c.adresa, c.localitate, c.judet, c.radiat
            FROM companies_fts f
            JOIN companies c ON c.id = f.rowid
            WHERE companies_fts MATCH :q
            ORDER BY c.radiat ASC, rank
            LIMIT :limit
        ');
        $stmt->bindValue(':q', '{cui}: ' . $ftsQuery, \PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return $this->formatResults($stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    private function searchByName(\PDO $pdo, string $name, int $limit): array
    {
        // Normalize input for accent-insensitive search
        $normalized = $this->normalize($name);
        $tokens = preg_split('/\s+/', $normalized);

        // Build FTS query: each token as prefix
        $ftsTokens = array_map(fn(string $t) => '"' . $this->escapeFts($t) . '"*', $tokens);
        $ftsQuery = '{denumire_norm}: ' . implode(' ', $ftsTokens);

        $stmt = $pdo->prepare('
            SELECT c.denumire, c.cui, c.cod_inmatriculare, c.adresa, c.localitate, c.judet, c.radiat
            FROM companies_fts f
            JOIN companies c ON c.id = f.rowid
            WHERE companies_fts MATCH :q
            ORDER BY c.radiat ASC, rank
            LIMIT :limit
        ');
        $stmt->bindValue(':q', $ftsQuery, \PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return $this->formatResults($stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    private function formatResults(array $rows): array
    {
        return array_map(fn(array $row) => [
            'denumire' => $row['denumire'],
            'cod_unic' => $row['cui'],
            'cod_inmatriculare' => $row['cod_inmatriculare'] ?: null,
            'adresa' => $row['adresa'] ?: null,
            'localitate' => $row['localitate'] ?: null,
            'nume_judet' => $row['judet'] ?: null,
            'radiat' => (bool) $row['radiat'],
        ], $rows);
    }

    private ?array $localities = null;

    /**
     * Get cities for a Romanian county from the localitati.json reference data.
     *
     * @return array<int, array{label: string, value: string}>
     */
    public function getCities(string $countyCode, string $search = ''): array
    {
        $data = $this->loadLocalities();
        $code = strtoupper($countyCode);

        if (!isset($data[$code])) {
            return [];
        }

        $cities = $data[$code];

        if ($search !== '' && mb_strlen($search) >= 1) {
            $needle = mb_strtolower($search, 'UTF-8');
            $cities = array_filter($cities, fn(string $city) =>
                str_contains(mb_strtolower($city, 'UTF-8'), $needle)
            );
        }

        $results = [];
        foreach (array_slice(array_values($cities), 0, 100) as $city) {
            $results[] = ['label' => $city, 'value' => $city];
        }

        return $results;
    }

    private function loadLocalities(): array
    {
        if ($this->localities === null) {
            $path = $this->dataDir . '/localitati.json';
            if (file_exists($path)) {
                $this->localities = json_decode(file_get_contents($path), true) ?? [];
            } else {
                $this->localities = [];
            }
        }

        return $this->localities;
    }

    private function normalize(string $text): string
    {
        $map = [
            'ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't',
            'Ă' => 'A', 'Â' => 'A', 'Î' => 'I', 'Ș' => 'S', 'Ş' => 'S', 'Ț' => 'T', 'Ţ' => 'T',
        ];

        $text = strtr($text, $map);
        $text = mb_strtoupper($text, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', trim($text));

        return $text;
    }

    private function escapeFts(string $value): string
    {
        // Escape double quotes for FTS5
        return str_replace('"', '""', $value);
    }

    private function getConnection(): \PDO
    {
        if ($this->pdo === null) {
            $this->pdo = new \PDO('sqlite:' . $this->dbPath, null, null, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::SQLITE_ATTR_OPEN_FLAGS => \PDO::SQLITE_OPEN_READONLY,
            ]);
            $this->pdo->exec('PRAGMA query_only = ON');
        }

        return $this->pdo;
    }
}
