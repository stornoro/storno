<?php

declare(strict_types=1);

namespace App\Service\Dosar;

use Smalot\PdfParser\Parser;

/**
 * Reads ANAF's "Registrul contractelor de locațiune" (the answer to a C168 SPV request):
 * a landscape table with one row per filing (initial, rectifying, amendment, termination).
 *
 * The PDF text is positioned, not linear: the page is rotated, so the text matrix's x is
 * the row axis and y the column axis. Rows start at the "INTERNT-" fragment; each cell is
 * recognised by its column band, and multi-line cells are joined in reading order.
 */
final class C168RegistryParser
{
    private const ROW_HEIGHT = 56.0;

    /** column bands on the y axis (points), measured on ANAF's layout */
    private const COLUMNS = [
        'crt' => [0, 36],
        'index' => [36, 76],
        'dataInregistrare' => [90, 121],
        'mod' => [135, 151],
        'tipDepunere' => [155, 181],
        'tipOperatie' => [185, 206],
        'nrContract' => [210, 226],
        'dataContract' => [230, 256],
        'dataInceput' => [265, 291],
        'dataSfarsit' => [298, 326],
        'modNr' => [335, 356],
        'modDataContract' => [360, 381],
        'modDataInceput' => [388, 411],
        'modDataSfarsit' => [418, 441],
        'incNr' => [452, 471],
        'incData' => [475, 501],
        'chirie' => [515, 563],
        'moneda' => [563, 586],
        'adresa' => [590, 700],
        'chirias' => [735, 820],
    ];

    /**
     * @return array{locator: array{cif: ?string, nume: ?string, adresa: ?string}, rows: list<array<string, mixed>>, contracts: list<array<string, mixed>>}
     */
    public function parse(string $pdf): array
    {
        $document = (new Parser())->parseContent($pdf);
        $pages = [];
        foreach ($document->getPages() as $page) {
            $items = [];
            foreach ($page->getDataTm() as [$tm, $text]) {
                $text = trim((string) $text);
                if ($text === '') {
                    continue;
                }
                $items[] = ['x' => (float) $tm[4], 'y' => (float) $tm[5], 'text' => $text];
            }
            $pages[] = $items;
        }

        return $this->parseItems($pages);
    }

    /**
     * @param list<list<array{x: float, y: float, text: string}>> $pages
     * @return array{locator: array{cif: ?string, nume: ?string, adresa: ?string}, rows: list<array<string, mixed>>, contracts: list<array<string, mixed>>}
     */
    public function parseItems(array $pages): array
    {
        $locator = ['cif' => null, 'nume' => null, 'adresa' => null];
        $rows = [];
        $locatorPage = true;
        foreach ($pages as $items) {
            // header: landlord CIF, name, address sit left of the first row anchor
            $anchors = [];
            foreach ($items as $it) {
                if ($it['text'] === 'INTERNT-') {
                    $anchors[] = $it['x'];
                }
            }
            sort($anchors);
            $firstAnchor = $anchors[0] ?? PHP_FLOAT_MAX;
            foreach ($items as $it) {
                if ($it['x'] >= $firstAnchor - 3) {
                    continue;
                }
                if ($locator['cif'] === null && preg_match('/^\d{2,13}$/', $it['text']) && $it['y'] > 80 && $it['y'] < 130) {
                    $locator['cif'] = $it['text'];
                } elseif ($locator['nume'] === null && $it['y'] > 150 && $it['y'] < 330 && preg_match('/^[\p{Lu}][\p{Lu}\s\-.]+$/u', $it['text']) && !str_starts_with($it['text'], 'REGISTRUL')) {
                    $locator['nume'] = $it['text'];
                } elseif ($locatorPage && $it['y'] > 400 && $it['y'] < 760 && $it['x'] < 100 && !str_contains($it['text'], 'Finan') && !str_contains($it['text'], 'Administra')) {
                    $locator['adresa'] = trim(($locator['adresa'] ?? '') . ' ' . $it['text']);
                }
            }
            $locatorPage = false;
            if ($anchors === []) {
                continue;
            }
            // rows: each item belongs to the band [anchor, nextAnchor)
            // a row is about 53 points tall; the last band must not swallow the page footer
            $bands = [];
            foreach ($anchors as $i => $a) {
                $next = $anchors[$i + 1] ?? ($a + self::ROW_HEIGHT);
                $bands[] = ['from' => $a - 3, 'to' => min($next, $a + self::ROW_HEIGHT) - 3, 'cells' => []];
            }
            foreach ($items as $it) {
                foreach ($bands as $bi => $band) {
                    if ($it['x'] >= $band['from'] && $it['x'] < $band['to']) {
                        $col = $this->column($it['y']);
                        if ($col !== null) {
                            $bands[$bi]['cells'][$col][] = $it;
                        }
                        break;
                    }
                }
            }
            foreach ($bands as $band) {
                $rows[] = $this->row($band['cells']);
            }
        }
        $rows = array_values(array_filter($rows, fn ($r) => $r['index'] !== null));

        return ['locator' => $locator, 'rows' => $rows, 'contracts' => $this->contracts($rows)];
    }

    private function column(float $y): ?string
    {
        foreach (self::COLUMNS as $name => [$from, $to]) {
            if ($y >= $from && $y < $to) {
                return $name;
            }
        }

        return null;
    }

    /** @param array<string, list<array{x: float, y: float, text: string}>> $cells */
    private function row(array $cells): array
    {
        $join = function (string $col, string $glue = ' ') use ($cells): ?string {
            if (!isset($cells[$col])) {
                return null;
            }
            $parts = $cells[$col];
            usort($parts, fn ($a, $b) => [$a['x'], $a['y']] <=> [$b['x'], $b['y']]);
            if ($col === 'index') {
                $parts = array_values(array_filter($parts, fn ($p) => preg_match('/^(INTERNT-?|\d+-?|\d+-\d{4})$/', $p['text']) === 1));
            }
            $s = '';
            foreach ($parts as $p) {
                // a hyphenated name split over two lines ("CETERAS MARIA-" + "ANDREEA") joins without a space
                $s .= ($s === '' || $glue === '' || str_ends_with($s, '-')) ? $p['text'] : $glue . $p['text'];
            }
            $s = trim($s);

            return $s === '' ? null : $s;
        };
        $date = fn (string $col) => $this->date($join($col, ''));
        $index = $join('index', '');
        $index = $index !== null ? preg_replace('/^INTERNT-?/', '', str_replace(' ', '', $index)) : null;
        $index = $index !== null ? preg_replace('/-(\d{4})$/', '', rtrim($index, '-')) : null;
        $chirie = $join('chirie', '');
        $tip = $join('tipOperatie', '');

        return [
            'crt' => preg_replace('/\D.*$/', '', (string) $join('crt', '')) ?: null,
            'index' => $index !== '' ? $index : null,
            'dataInregistrare' => $date('dataInregistrare'),
            'mod' => $join('mod', ''),
            'tipDepunere' => $join('tipDepunere', ''),
            'tipOperatie' => $tip,
            'operatie' => match ($tip) { 'I' => 'inregistrare', 'M' => 'modificare', 'S' => 'incetare', default => null },
            'nrContract' => $join('nrContract', ''),
            'dataContract' => $date('dataContract'),
            'dataInceput' => $date('dataInceput'),
            'dataSfarsit' => $date('dataSfarsit'),
            'modificare' => ['nr' => $join('modNr', ''), 'dataContract' => $date('modDataContract'), 'dataInceput' => $date('modDataInceput'), 'dataSfarsit' => $date('modDataSfarsit')],
            'incetare' => ['nr' => $join('incNr', ''), 'data' => $date('incData')],
            'chirie' => $chirie !== null && is_numeric($chirie) ? (float) $chirie : null,
            'moneda' => $join('moneda', ''),
            'adresa' => $join('adresa'),
            'chirias' => $join('chirias'),
        ];
    }

    /** "16/04/2026" or "16/04/ 2026" → "16.04.2026" */
    private function date(?string $s): ?string
    {
        if ($s === null) {
            return null;
        }
        $s = preg_replace('/\s+/', '', $s) ?? $s;
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})/', $s, $m)) {
            return sprintf('%02d.%02d.%s', (int) $m[1], (int) $m[2], $m[3]);
        }

        return null;
    }

    /**
     * One entry per contract (number + date + tenant), with its current state after all filings.
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function contracts(array $rows): array
    {
        $byKey = [];
        foreach ($rows as $r) {
            $key = implode('|', [$r['nrContract'], $r['dataContract'], mb_strtoupper((string) $r['chirias'])]);
            $c = $byKey[$key] ?? [
                'numar' => $r['nrContract'], 'data' => $r['dataContract'], 'chirias' => $r['chirias'], 'adresa' => $r['adresa'],
                'chirie' => $r['chirie'], 'moneda' => $r['moneda'], 'deLa' => $r['dataInceput'], 'panaLa' => $r['dataSfarsit'],
                'dataIncetare' => null, 'incetareNumar' => null, 'incetareData' => null, 'modificareNumar' => null, 'dataModificare' => null,
                'filings' => [], 'lastIndex' => null, 'lastDataInregistrare' => null,
            ];
            $c['filings'][] = ['index' => $r['index'], 'data' => $r['dataInregistrare'], 'tipDepunere' => $r['tipDepunere'], 'operatie' => $r['operatie']];
            if ($r['dataInregistrare'] !== null && ($c['lastDataInregistrare'] === null || $this->iso($r['dataInregistrare']) >= $this->iso($c['lastDataInregistrare']))) {
                $c['lastDataInregistrare'] = $r['dataInregistrare'];
                $c['lastIndex'] = $r['index'];
                $c['adresa'] = $r['adresa'] ?? $c['adresa'];
                $c['chirie'] = $r['chirie'] ?? $c['chirie'];
                $c['moneda'] = $r['moneda'] ?? $c['moneda'];
            }
            if ($r['operatie'] === 'modificare' && $r['modificare']['dataContract'] !== null) {
                $c['modificareNumar'] = $r['modificare']['nr'];
                $c['dataModificare'] = $r['modificare']['dataContract'];
                $c['panaLa'] = $r['modificare']['dataSfarsit'] ?? $c['panaLa'];
            }
            if ($r['operatie'] === 'incetare' && $r['incetare']['data'] !== null) {
                $c['dataIncetare'] = $r['incetare']['data'];
                $c['incetareNumar'] = $r['incetare']['nr'];
                $c['incetareData'] = $r['incetare']['data'];
            }
            $byKey[$key] = $c;
        }
        $today = date('Ymd');
        $out = [];
        foreach ($byKey as $c) {
            $end = $c['dataIncetare'] ?? $c['panaLa'];
            $c['stare'] = $c['dataIncetare'] !== null ? 'incetat' : (($end !== null && $this->iso($end) < $today) ? 'expirat' : 'activ');
            $out[] = $c;
        }
        usort($out, fn ($a, $b) => $this->iso($b['data'] ?? '01.01.1900') <=> $this->iso($a['data'] ?? '01.01.1900'));

        return $out;
    }

    private function iso(string $ddmmyyyy): string
    {
        return preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $ddmmyyyy, $m) ? $m[3] . $m[2] . $m[1] : '00000000';
    }
}
