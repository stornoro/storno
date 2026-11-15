<?php

declare(strict_types=1);

namespace App\Service\Spv;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Reads the balance out of an ANAF "Fisa sinteticǎ totalǎ" (fișa pe plătitor / fișa rol) PDF.
 *
 * The document closes with one line per tax ("Total impozit:") and one for the whole
 * taxpayer ("Total cod fiscal: <cif>"), each carrying, in this order: obligations
 * established, obligations unpaid, interest computed and not yet charged, late-payment
 * penalties computed and not yet charged, amounts collected. The unpaid column is what
 * a somație would be issued for; interest and penalties are what the next decision on
 * accessories would charge. The header says up to which day the accessories were computed.
 *
 * Text is taken with poppler's `pdftotext -layout`; without the binary nothing is parsed.
 */
final class FisaRolBalanceParser
{
    private ?string $pdftotext;

    public function __construct(?string $pdftotextBinary = null)
    {
        $this->pdftotext = $pdftotextBinary ?: (new ExecutableFinder())->find('pdftotext');
    }

    public function isAvailable(): bool
    {
        return $this->pdftotext !== null;
    }

    /**
     * @return array{cif: string, asOf: ?string, obligations: float, unpaid: float, interest: float, penalties: float, paid: float,
     *   unpaidByTax: list<array{tax: string, unpaid: float, interest: float, penalties: float}>}|null
     *   null when the PDF is not a fișa rol or could not be read
     */
    public function parsePdf(string $binary): ?array
    {
        if ($this->pdftotext === null || !str_starts_with($binary, '%PDF')) {
            return null;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'fisa_rol_');
        if ($tmp === false) {
            return null;
        }
        try {
            file_put_contents($tmp, $binary);
            $process = new Process([$this->pdftotext, '-layout', $tmp, '-']);
            $process->setTimeout(60);
            $process->run();
            if (!$process->isSuccessful()) {
                return null;
            }

            return $this->parseText($process->getOutput());
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * @return array{cif: string, asOf: ?string, obligations: float, unpaid: float, interest: float, penalties: float, paid: float,
     *   unpaidByTax: list<array{tax: string, unpaid: float, interest: float, penalties: float}>}|null
     */
    public function parseText(string $text): ?array
    {
        if (!preg_match('/Total cod fiscal:\s*(\d{2,13})\s+' . self::numbers(5) . '/u', $text, $m)) {
            return null;
        }
        $result = [
            'cif' => $m[1],
            'asOf' => null,
            'obligations' => self::amount($m[2]),
            'unpaid' => self::amount($m[3]),
            'interest' => self::amount($m[4]),
            'penalties' => self::amount($m[5]),
            'paid' => self::amount($m[6]),
            'unpaidByTax' => [],
        ];

        if (preg_match('/Calcul accesorii pana la data de\s*:?\s*(\d{2})[\/.](\d{2})[\/.](\d{4})/u', $text, $d)) {
            $result['asOf'] = sprintf('%s-%s-%s', $d[3], $d[2], $d[1]);
        }

        // Per-tax totals: the section title is the last "<code>  <Name>" line before each "Total impozit:".
        $section = null;
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            // "        3   Impozit pe profit" — a code and a name made of words only (document rows carry dates and amounts)
            if (preg_match('/^\s*\d{1,4}\s{2,}([A-Z][a-z][A-Za-z ,()\-]{3,})\s*$/u', $line, $s)) {
                $section = trim(preg_replace('/\s{2,}/', ' ', $s[1]) ?? $s[1]);
                continue;
            }
            if (preg_match('/Total impozit:\s+' . self::numbers(5) . '/u', $line, $t)) {
                $unpaid = self::amount($t[2]);
                $interest = self::amount($t[3]);
                $penalties = self::amount($t[4]);
                if ($section !== null && ($unpaid > 0 || $interest > 0 || $penalties > 0)) {
                    $result['unpaidByTax'][] = ['tax' => $section, 'unpaid' => $unpaid, 'interest' => $interest, 'penalties' => $penalties];
                }
                $section = null;
            }
        }

        return $result;
    }

    /** `n` whitespace-separated amounts (digits with optional thousands dots / decimal comma, possibly negative). */
    private static function numbers(int $n): string
    {
        return implode('\s+', array_fill(0, $n, '(-?[\d.]+(?:,\d+)?)'));
    }

    private static function amount(string $raw): float
    {
        $normalized = str_replace('.', '', $raw);
        $normalized = str_replace(',', '.', $normalized);

        return (float) $normalized;
    }
}
