<?php

namespace App\Service\Borderou\Pdf;

/**
 * Groups words into visual lines: words whose vertical centre lies within
 * $tolerance points of the running average of the current line belong to it.
 * Lines are returned top-to-bottom, words left-to-right.
 */
final class LineClusterer
{
    /**
     * @param PdfWord[] $words
     * @return array<int, PdfWord[]>
     */
    public function cluster(array $words, float $tolerance = 2.5): array
    {
        if ($words === []) {
            return [];
        }

        usort($words, static function (PdfWord $a, PdfWord $b) use ($tolerance): int {
            $dy = $a->centerY() - $b->centerY();
            if (abs($dy) <= $tolerance) {
                return $a->x0 <=> $b->x0;
            }

            return $dy < 0 ? -1 : 1;
        });

        $lines = [];
        $current = [];
        $sum = 0.0;
        foreach ($words as $word) {
            if ($current === []) {
                $current = [$word];
                $sum = $word->centerY();
                continue;
            }
            $avg = $sum / count($current);
            if (abs($word->centerY() - $avg) <= $tolerance) {
                $current[] = $word;
                $sum += $word->centerY();
                continue;
            }
            $lines[] = $current;
            $current = [$word];
            $sum = $word->centerY();
        }
        if ($current !== []) {
            $lines[] = $current;
        }

        foreach ($lines as &$line) {
            usort($line, static fn (PdfWord $a, PdfWord $b) => $a->x0 <=> $b->x0);
        }

        return $lines;
    }

    /**
     * @param PdfWord[] $line
     */
    public static function text(array $line): string
    {
        return implode(' ', array_map(fn (PdfWord $w) => $w->text, $line));
    }
}
