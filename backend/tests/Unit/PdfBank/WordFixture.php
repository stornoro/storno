<?php

namespace App\Tests\Unit\PdfBank;

use App\Service\Borderou\Pdf\PdfPage;
use App\Service\Borderou\Pdf\PdfWord;

/**
 * Builds PdfPage objects from a compact text layout so parser tests can
 * describe a statement the way it looks on paper.
 *
 * Each row is [yTop, [[x, "text"], [x, "text"], ...]] in top-left coordinates
 * (y grows downwards, like pdftotext -bbox-layout). Words get a width of
 * 5pt per character and a height of 10pt unless overridden.
 */
final class WordFixture
{
    /**
     * @param array<int, array{0: float, 1: array<int, array{0: float, 1: string}>}> $rows
     */
    public static function page(array $rows, int $number = 1, float $width = 595.0, float $height = 842.0, float $charWidth = 5.0, float $lineHeight = 10.0): PdfPage
    {
        $words = [];
        foreach ($rows as [$y, $cells]) {
            foreach ($cells as [$x, $text]) {
                foreach (preg_split('/\s+/', trim($text)) ?: [] as $token) {
                    if ($token === '') {
                        continue;
                    }
                    $w = mb_strlen($token) * $charWidth;
                    $words[] = new PdfWord($token, $x, $y, $x + $w, $y + $lineHeight);
                    $x += $w + $charWidth; // one space between words
                }
            }
        }

        return new PdfPage($number, $words, $width, $height);
    }

    /**
     * Same as page() but each row is a string with columns separated by two or more
     * spaces; column x positions are derived from the character offsets (5pt per char).
     * Note: offsets are BYTE offsets (preg_match_all) while widths use mb_strlen, so a
     * diacritic in a row shifts every token to its right by 5pt per extra byte. Keep
     * fixtures ASCII, or place tokens by byte offset on purpose.
     *
     * @param string[] $rows
     */
    public static function fromLayout(array $rows, int $number = 1, float $charWidth = 5.0, float $lineHeight = 12.0, float $top = 40.0, float $width = 595.0, float $height = 842.0): PdfPage
    {
        $words = [];
        $y = $top;
        foreach ($rows as $row) {
            if (preg_match_all('/\S+/', $row, $m, PREG_OFFSET_CAPTURE)) {
                foreach ($m[0] as [$token, $offset]) {
                    $x = $offset * $charWidth;
                    $words[] = new PdfWord($token, $x, $y, $x + mb_strlen($token) * $charWidth, $y + $lineHeight - 2);
                }
            }
            $y += $lineHeight;
        }

        return new PdfPage($number, $words, $width, $height);
    }
}
