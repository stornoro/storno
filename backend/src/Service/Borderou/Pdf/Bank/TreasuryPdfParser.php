<?php

namespace App\Service\Borderou\Pdf\Bank;

use App\Service\Borderou\Pdf\AbstractPdfStatementParser;
use App\Service\Borderou\Pdf\PdfPage;
use App\Service\Borderou\Pdf\PdfStatement;
use App\Service\Borderou\Pdf\PdfStatementNotRecognizedException;
use App\Service\Borderou\Pdf\PdfStatementTransaction;
use App\Service\Borderou\Pdf\PdfWord;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Trezoreria Statului "EXTRAS DE CONT". The PDF carries the statement as an embedded
 * XML attachment (<extras> / <cont_ext> / <cont_misc>); when the source path is known and
 * poppler's `pdfdetach` is available the XML is used, otherwise the printed table
 * ("NR. DOCUMENT / DATA DOC / DATA PLATII / Nr. referinta / IBAN / COD / NUME / DEBIT / CREDIT / ... / EXPLICATII")
 * is parsed from the page words. One statement per account (cont_ext / "CONT ..." block).
 */
class TreasuryPdfParser extends AbstractPdfStatementParser
{
    private const NO_XML_MESSAGE = 'Atasamentul *.xml al extrasului de trezorerie nu a fost gasit.';

    private ?string $sourcePath = null;
    private ?string $password = null;
    private ?string $pdfdetach;

    public function __construct(?string $pdfdetachBinary = null)
    {
        parent::__construct();
        $this->pdfdetach = $pdfdetachBinary ?: (new ExecutableFinder())->find('pdfdetach');
    }

    public function getBankKey(): string
    {
        return 'trezorerie';
    }

    public function getBankLabel(): string
    {
        return 'Trezoreria Statului';
    }

    /**
     * Path of the PDF being parsed (the parser interface only receives words). When set,
     * parse() reads the embedded XML attachment instead of the printed table.
     */
    public function setSourcePath(?string $path, ?string $password = null): void
    {
        $this->sourcePath = $path;
        $this->password = $password;
    }

    public function hasPdfdetach(): bool
    {
        return $this->pdfdetach !== null;
    }

    public function score(array $page1Words): int
    {
        $t = $this->normalise(implode(' ', $page1Words));
        $a = str_contains($t, 'trezorerie');
        $b = str_contains($t, 'intocmit si verificat');
        $c = str_contains($t, 'extras de cont');
        if ($a && $b && $c) {
            return 100;
        }

        return ($a || $b) ? 60 : 0;
    }

    public function parse(array $pages): array
    {
        if ($this->sourcePath !== null && $this->pdfdetach !== null && is_file($this->sourcePath)) {
            try {
                return $this->parseFile($this->sourcePath, $this->password);
            } catch (\RuntimeException) {
                // no usable attachment: fall back to the printed table
            }
        }

        return $this->parseText($pages);
    }

    /**
     * Reads the XML attachment of a treasury PDF.
     *
     * @return PdfStatement[]
     */
    public function parseFile(string $pdfPath, ?string $password = null): array
    {
        $xml = $this->extractXmlAttachment($pdfPath, $password);
        if ($xml === null) {
            throw new \RuntimeException(self::NO_XML_MESSAGE);
        }

        return $this->parseXml($xml);
    }

    // ------------------------------------------------------------------
    // XML
    // ------------------------------------------------------------------

    /**
     * @return PdfStatement[]
     */
    public function parseXml(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        $loaded = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOENT | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded || !$doc->documentElement || $doc->documentElement->tagName !== 'extras') {
            throw new \RuntimeException('Atasamentul XML al extrasului de trezorerie nu a putut fi citit.');
        }
        $root = $doc->documentElement;
        $holder = trim($root->getAttribute('Denumire_EP'));
        $holder = $holder === '' ? null : $holder;
        $statementDate = $this->parseTreasuryDate($root->getAttribute('Data_extras'));

        $statements = [];
        foreach ($this->children($root, 'cont_ext') as $contExt) {
            $iban = null;
            $cont = $this->children($contExt, 'cont')[0] ?? null;
            if ($cont !== null) {
                $iban = strtoupper(trim($cont->getAttribute('Cod_IBAN'))) ?: null;
            }
            $opening = $this->netBalance($this->children($contExt, 'Sold_precedent')[0] ?? null);
            $closing = $this->netBalance($this->children($contExt, 'Sold_final')[0] ?? null);

            $transactions = [];
            $warnings = [];
            $running = $opening;
            foreach ($this->children($contExt, 'cont_misc') as $misc) {
                $get = fn (string $name) => trim($this->childText($misc, $name));
                $sumac = $this->parseMoneyEn($get('sumac')) ?? '0.00';
                $sumad = $this->parseMoneyEn($get('sumad')) ?? '0.00';
                $isCredit = bccomp($sumac, '0', 2) !== 0;
                $amount = $this->bcAbs($isCredit ? $sumac : $sumad);
                $closingRow = $isCredit ? bcadd($running, $amount, 2) : bcsub($running, $amount, 2);
                $date = $this->parseTreasuryDate($get('databan')) ?? $this->parseTreasuryDate($get('datadoc')) ?? $statementDate;
                if ($date === null) {
                    $warnings[] = sprintf('Tranzactia %s nu are o data valida; a fost folosita data 0001-01-01.', $get('nrdoc'));
                    $date = new \DateTimeImmutable('0001-01-01');
                }
                $name = preg_replace('/\s+/', ' ', $get('numepb')) ?: null;
                $reference = $get('nrrefdest') ?: null;
                $cpIban = strtoupper($get('ibanbfpl')) ?: null;
                $raw = trim(implode(' ', array_filter([$get('nrdoc'), $get('datadoc'), $get('databan'), $get('nrrefdest'), $get('ibanbfpl'), $get('platitor'), $get('numepb'), $get('sumad'), $get('sumac'), $get('explicatii')], static fn ($v) => $v !== '')));
                $transactions[] = new PdfStatementTransaction(
                    $date,
                    'Cod Fiscal ' . $get('platitor') . '_' . $get('explicatii'),
                    $isCredit ? '0.00' : $amount,
                    $isCredit ? $amount : '0.00',
                    $reference,
                    $this->parseTreasuryDate($get('datadoc')),
                    $name,
                    $cpIban,
                    $closingRow,
                    'RON',
                    [$raw],
                );
                $running = $closingRow;
            }

            if (bccomp($closing, '0', 2) !== 0 && ($mismatch = $this->closingMismatch($closing, $running))) {
                $warnings[] = $mismatch;
            }
            if ($transactions === []) {
                $warnings[] = 'Nu a fost gasita nicio tranzactie in extras.';
            }
            $statements[] = new PdfStatement(
                $this->getBankKey(),
                $this->getBankLabel(),
                $iban,
                'RON',
                $transactions,
                $holder,
                null,
                $opening,
                $closing,
                $statementDate,
                $statementDate,
                $warnings,
            );
        }
        if ($statements === []) {
            throw new \RuntimeException('Atasamentul XML al extrasului de trezorerie nu contine niciun cont (cont_ext).');
        }

        return $statements;
    }

    /**
     * Extracts the first *.xml attachment with `pdfdetach`; null when there is none.
     */
    private function extractXmlAttachment(string $pdfPath, ?string $password): ?string
    {
        if ($this->pdfdetach === null) {
            return null;
        }
        $base = [$this->pdfdetach];
        if ($password !== null && $password !== '') {
            $base[] = '-upw';
            $base[] = $password;
        }
        $list = new Process([...$base, '-list', $pdfPath]);
        $list->setTimeout(30);
        $list->run();
        if (!$list->isSuccessful()) {
            return null;
        }
        $index = null;
        foreach (preg_split('/\R/', $list->getOutput()) ?: [] as $line) {
            if (preg_match('/^\s*(\d+):\s*(.+)$/', $line, $m) && preg_match('/\.xml\s*$/i', $m[2])) {
                $index = (int) $m[1];
                break;
            }
        }
        if ($index === null) {
            return null;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'trez_');
        if ($tmp === false) {
            return null;
        }
        try {
            $save = new Process([...$base, '-save', (string) $index, '-o', $tmp, $pdfPath]);
            $save->setTimeout(30);
            $save->run();
            if (!$save->isSuccessful()) {
                return null;
            }
            $xml = file_get_contents($tmp);
        } finally {
            @unlink($tmp);
        }
        if ($xml === false || trim($xml) === '') {
            return null;
        }
        if (!mb_check_encoding($xml, 'UTF-8')) {
            $xml = mb_convert_encoding($xml, 'UTF-8', 'ISO-8859-2');
        }

        return $xml;
    }

    /**
     * @return \DOMElement[]
     */
    private function children(\DOMElement $el, string $name): array
    {
        $out = [];
        foreach ($el->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->tagName === $name) {
                $out[] = $child;
            }
        }

        return $out;
    }

    private function childText(\DOMElement $el, string $name): string
    {
        $c = $this->children($el, $name)[0] ?? null;

        return $c === null ? '' : $c->textContent;
    }

    /**
     * Sumac - Sumad of a balance block (missing block or empty numbers count as 0).
     */
    private function netBalance(?\DOMElement $block): string
    {
        if ($block === null) {
            return '0.00';
        }
        $c = $this->parseMoneyEn(trim($this->childText($block, 'Sumac'))) ?? '0.00';
        $d = $this->parseMoneyEn(trim($this->childText($block, 'Sumad'))) ?? '0.00';

        return bcsub($c, $d, 2);
    }

    /**
     * yyyyMMdd (attachment), dd-MMM-yyyy / dd.MM.yyyy (Data_extras).
     */
    private function parseTreasuryDate(string $value): ?\DateTimeImmutable
    {
        $v = trim($value);
        if ($v === '') {
            return null;
        }
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $v, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return new \DateTimeImmutable(sprintf('%s-%s-%s', $m[1], $m[2], $m[3]));
        }

        return $this->parseDateStrict($v);
    }

    // ------------------------------------------------------------------
    // Printed table fallback
    // ------------------------------------------------------------------

    /**
     * @param PdfPage[] $pages
     * @return PdfStatement[]
     */
    private function parseText(array $pages): array
    {
        $statements = [];
        $block = null;
        $bounds = null;
        $headerSeen = false;
        $holder = null;
        $statementDate = null;

        $finish = function () use (&$block, &$statements, &$holder, &$statementDate): void {
            if ($block === null) {
                return;
            }
            $statements[] = $this->finishTextBlock($block, $holder, $statementDate);
            $block = null;
        };

        foreach ($pages as $page) {
            $lines = $this->clusterer->cluster($page->words, 2.5);
            $count = count($lines);
            for ($i = 0; $i < $count; $i++) {
                $line = $lines[$i];
                $text = $this->lineText($line);
                $norm = $this->normalise($text);

                if ($statementDate === null && preg_match('/la data:\s*(\d{2}\.\d{2}\.\d{4})/', $text, $m)) {
                    $statementDate = $this->parseDateStrict($m[1]);
                }
                // "CONT <account> <holder> <IBAN>" (a signature widget may append text after the IBAN)
                if (preg_match('/^CONT\s+(\S+)\s+(.*?)\s*\b([A-Z]{2}\d{2}[A-Z0-9]{11,30})\b/', $text, $m)) {
                    $finish();
                    $block = ['iban' => strtoupper($m[3]), 'account' => $m[1], 'opening' => null, 'closing' => null, 'rows' => [], 'pending' => [], 'warnings' => []];
                    $name = trim($m[2]);
                    if ($name !== '' && $holder === null) {
                        $holder = $name;
                    }
                    continue;
                }
                if ($this->isTextHeader($line)) {
                    $headerSeen = true;
                    $b = $this->textBoundaries($line, $i + 1 < $count ? $lines[$i + 1] : null);
                    if ($b !== null) {
                        $bounds = $b;
                    }
                    continue;
                }
                if ($block === null || $bounds === null) {
                    continue;
                }
                if (str_starts_with($norm, 'sold precedent') || str_starts_with($norm, 'sold final') || str_starts_with($norm, 'rulaj zi') || str_starts_with($norm, 'total sume')) {
                    $cells = $this->splitByBoundaries($line, $bounds, true);
                    $d = $this->parseMoneyEn($cells[7]) ?? '0.00';
                    $c = $this->parseMoneyEn($cells[8]) ?? '0.00';
                    if (str_starts_with($norm, 'sold precedent')) {
                        $block['opening'] = bcsub($c, $d, 2);
                    } elseif (str_starts_with($norm, 'sold final')) {
                        $block['closing'] = bcsub($c, $d, 2);
                    }
                    continue;
                }
                if (str_starts_with($norm, 'intocmit si verificat') || str_starts_with($norm, 'nr. document') || str_starts_with($norm, 'document doc')) {
                    continue;
                }
                $cells = $this->splitByBoundaries($line, $bounds, true);
                $y = $this->centreY($line);
                // anchor: document number (digits or alphanumeric), a date in DATA DOC or DATA PLATII, an amount
                if (trim($cells[0]) !== ''
                    && ($this->looksLikeDate(trim($cells[1])) || $this->looksLikeDate(trim($cells[2])))
                    && ($this->parseMoneyEn($cells[7]) !== null || $this->parseMoneyEn($cells[8]) !== null)
                ) {
                    $block['rows'][] = ['cells' => $cells, 'y' => $y, 'raw' => [$text], 'extraName' => [], 'extraExpl' => []];
                    continue;
                }
                // continuation line (name / explicatii wrap, printed above or below the anchor line)
                $block['pending'][] = ['cells' => $cells, 'y' => $y, 'text' => $text];
            }
        }
        $finish();

        if (!$headerSeen) {
            throw new PdfStatementNotRecognizedException('Nu am putut identifica antetul tabelului (NR. DOCUMENT / DATA DOC / DATA PLATII / DEBIT / CREDIT) in extrasul de trezorerie. Este posibil ca formatul extrasului sa fi fost modificat.');
        }
        if ($statements === []) {
            throw new PdfStatementNotRecognizedException('Nu am putut identifica niciun cont (CONT ... IBAN) in extrasul de trezorerie. Este posibil ca formatul extrasului sa fi fost modificat.');
        }

        return $statements;
    }

    /**
     * @param array{iban: string, account: string, opening: ?string, closing: ?string, rows: array<int, array{cells: string[], y: float, raw: string[], extraName: array<int, array{y: float, text: string}>, extraExpl: array<int, array{y: float, text: string}>}>, pending: array<int, array{cells: string[], y: float, text: string}>, warnings: string[]} $block
     */
    private function finishTextBlock(array $block, ?string $holder, ?\DateTimeImmutable $statementDate): PdfStatement
    {
        // attach every continuation line to the vertically nearest transaction row (within 12pt)
        foreach ($block['pending'] as $pending) {
            $bestIdx = null;
            $bestDist = 12.0;
            foreach ($block['rows'] as $idx => $row) {
                $d = abs($row['y'] - $pending['y']);
                if ($d < $bestDist) {
                    $bestDist = $d;
                    $bestIdx = $idx;
                }
            }
            if ($bestIdx === null) {
                continue;
            }
            $c = $pending['cells'];
            if (trim($c[6]) !== '') {
                $block['rows'][$bestIdx]['extraName'][] = ['y' => $pending['y'], 'text' => trim($c[6])];
            }
            if (trim($c[12]) !== '') {
                $block['rows'][$bestIdx]['extraExpl'][] = ['y' => $pending['y'], 'text' => trim($c[12])];
            }
            $block['rows'][$bestIdx]['raw'][] = $pending['text'];
        }

        $transactions = [];
        $warnings = $block['warnings'];
        $running = $block['opening'] ?? '0.00';
        foreach ($block['rows'] as $row) {
            $c = $row['cells'];
            $debit = $this->parseMoneyEn($c[7]) ?? '0.00';
            $credit = $this->parseMoneyEn($c[8]) ?? '0.00';
            $isCredit = bccomp($credit, '0', 2) !== 0;
            $amount = $this->bcAbs($isCredit ? $credit : $debit);
            $closing = $isCredit ? bcadd($running, $amount, 2) : bcsub($running, $amount, 2);
            $date = $this->parseDate(trim($c[2]), ['d.m.Y']) ?? $this->parseDate(trim($c[1]), ['d.m.Y']) ?? $statementDate;
            if ($date === null) {
                $warnings[] = 'Rand cu suma fara data: ' . implode(' ', $row['raw']);
                continue;
            }
            $name = $this->joinAround($row['y'], trim($c[6]), $row['extraName']);
            $explicatii = $this->joinAround($row['y'], trim($c[12]), $row['extraExpl']);
            $cpIban = $this->extractIban(trim($c[4]));
            $platitor = trim($c[5]);
            $transactions[] = new PdfStatementTransaction(
                $date,
                'Cod Fiscal ' . $platitor . '_' . $explicatii,
                $isCredit ? '0.00' : $amount,
                $isCredit ? $amount : '0.00',
                trim($c[3]) === '' ? null : trim($c[3]),
                $this->parseDate(trim($c[1]), ['d.m.Y']),
                $name === '' ? null : $name,
                $cpIban,
                $closing,
                'RON',
                $row['raw'],
            );
            $running = $closing;
        }
        if ($block['closing'] !== null && bccomp($block['closing'], '0', 2) !== 0 && ($mismatch = $this->closingMismatch($block['closing'], $running))) {
            $warnings[] = $mismatch;
        }
        if ($transactions === []) {
            $warnings[] = 'Nu a fost gasita nicio tranzactie in extras.';
        }

        return new PdfStatement(
            $this->getBankKey(),
            $this->getBankLabel(),
            $block['iban'],
            'RON',
            $transactions,
            $holder,
            null,
            $block['opening'],
            $block['closing'],
            $statementDate,
            $statementDate,
            $warnings,
        );
    }

    /**
     * Joins the anchor cell with its continuation fragments in vertical order.
     *
     * @param array<int, array{y: float, text: string}> $extra
     */
    private function joinAround(float $anchorY, string $anchorText, array $extra): string
    {
        $parts = $extra;
        if ($anchorText !== '') {
            $parts[] = ['y' => $anchorY, 'text' => $anchorText];
        }
        usort($parts, static fn (array $a, array $b) => $a['y'] <=> $b['y']);

        return trim(implode(' ', array_column($parts, 'text')));
    }

    /**
     * @param PdfWord[] $line
     */
    private function isTextHeader(array $line): bool
    {
        $texts = array_map(static fn (PdfWord $w) => strtoupper($w->text), $line);

        return in_array('DEBIT', $texts, true) && in_array('CREDIT', $texts, true) && in_array('NUME', $texts, true) && in_array('NR.', $texts, true);
    }

    /**
     * 13 columns; words are assigned by centre because the amounts are right-aligned.
     *
     * @param PdfWord[] $header
     * @param PdfWord[]|null $next second header line (DOCUMENT / DOC / PLATII / ... / EXPLICATII)
     * @return float[]|null
     */
    private function textBoundaries(array $header, ?array $next): ?array
    {
        $data = [];
        $iban = $cod = $nume = $debit = $credit = $codAng = $indicator = $codProg = null;
        foreach ($header as $w) {
            $t = strtoupper($w->text);
            if ($t === 'DATA') {
                $data[] = $w;
            } elseif ($t === 'IBAN') {
                $iban ??= $w;
            } elseif ($t === 'COD' && $w->x0 < ($debit?->x0 ?? INF)) {
                $cod ??= $w;
            } elseif ($t === 'NUME') {
                $nume ??= $w;
            } elseif ($t === 'DEBIT') {
                $debit ??= $w;
            } elseif ($t === 'CREDIT') {
                $credit ??= $w;
            } elseif ($t === 'COD' && $debit !== null) {
                if ($codAng === null) {
                    $codAng = $w;
                } else {
                    $codProg ??= $w;
                }
            } elseif ($t === 'INDICATOR') {
                $indicator ??= $w;
            }
        }
        // "Nr." (referinta) is mixed case, unlike the "NR." of the first and last columns
        $ref = null;
        foreach ($header as $w) {
            if ($w->text === 'Nr.' && $data !== [] && $w->x0 > $data[count($data) - 1]->x0) {
                $ref = $w;
                break;
            }
        }
        $intern = null;
        foreach ($header as $w) {
            if ($w->text === 'NR.' && $credit !== null && $w->x0 > $credit->x0) {
                $intern = $w;
                break;
            }
        }
        if ($intern === null && $next !== null) {
            foreach ($next as $w) {
                if (strtoupper($w->text) === 'EXPLICATII') {
                    $intern = $w;
                    break;
                }
            }
        }
        if (count($data) < 2 || !$ref || !$iban || !$cod || !$nume || !$debit || !$credit) {
            return null;
        }
        $afterCredit = $codAng ? ($credit->x1 + $codAng->x0) / 2 : $credit->x1 + 30.0;
        $internX = $intern ? $intern->x0 - 5.0 : ($codProg ? $codProg->x1 + 30.0 : $afterCredit + 200.0);

        return [
            -INF,
            $data[0]->x0 - 5.0,
            $data[1]->x0 - 5.0,
            $ref->x0 - 5.0,
            $iban->x0 - 5.0,
            $cod->x0 - 5.0,
            $nume->x0 - 5.0,
            $debit->x0 - 60.0,
            ($debit->x1 + $credit->x0) / 2,
            $afterCredit,
            $indicator ? $indicator->x0 - 5.0 : $afterCredit + 1.0,
            $codProg ? $codProg->x0 - 5.0 : ($indicator ? $indicator->x1 + 1.0 : $afterCredit + 2.0),
            $internX,
        ];
    }

    /**
     * @param PdfWord[] $line
     */
    private function centreY(array $line): float
    {
        $sum = 0.0;
        foreach ($line as $w) {
            $sum += $w->centerY();
        }

        return $sum / max(1, count($line));
    }
}
