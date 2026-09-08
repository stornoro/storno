<?php

namespace App\Service\Borderou\Pdf;

use Smalot\PdfParser\Parser as SmalotParser;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Extracts positioned words from a PDF.
 *
 * Primary path: poppler's `pdftotext -bbox-layout`, which yields one bounding
 * box per word. Fallback: smalot/pdfparser text matrices, where a text run is
 * split into words with estimated widths (less precise, but no binary needed).
 */
class PdfWordExtractor
{
    private ?string $pdftotext;

    public function __construct(?string $pdftotextBinary = null)
    {
        $this->pdftotext = $pdftotextBinary ?: (new ExecutableFinder())->find('pdftotext');
    }

    /**
     * @return PdfPage[]
     */
    public function extract(string $filePath, ?string $password = null): array
    {
        if ($this->pdftotext) {
            $pages = $this->extractWithPdftotext($filePath, $password);
            if ($pages !== null) {
                return $pages;
            }
        }

        return $this->extractWithSmalot($filePath);
    }

    public function hasPdftotext(): bool
    {
        return $this->pdftotext !== null;
    }

    /**
     * @return PdfPage[]|null null when pdftotext failed (caller falls back)
     */
    private function extractWithPdftotext(string $filePath, ?string $password): ?array
    {
        $cmd = [$this->pdftotext, '-bbox-layout'];
        if ($password !== null && $password !== '') {
            $cmd[] = '-upw';
            $cmd[] = $password;
        }
        $cmd[] = $filePath;
        $cmd[] = '-';

        $process = new Process($cmd);
        $process->setTimeout(60);
        $process->run();
        if (!$process->isSuccessful()) {
            if (str_contains($process->getErrorOutput(), 'Incorrect password')) {
                throw new PdfPasswordRequiredException();
            }

            return null;
        }

        $xml = $process->getOutput();
        if ($xml === '') {
            return null;
        }

        $previous = libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        $loaded = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOENT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            return null;
        }

        $pages = [];
        $pageNo = 0;
        foreach ($doc->getElementsByTagName('page') as $pageEl) {
            $pageNo++;
            $words = [];
            foreach ($pageEl->getElementsByTagName('word') as $wordEl) {
                $text = trim($wordEl->textContent);
                if ($text === '') {
                    continue;
                }
                $words[] = new PdfWord(
                    $text,
                    (float) $wordEl->getAttribute('xMin'),
                    (float) $wordEl->getAttribute('yMin'),
                    (float) $wordEl->getAttribute('xMax'),
                    (float) $wordEl->getAttribute('yMax'),
                );
            }
            $pages[] = new PdfPage(
                $pageNo,
                $words,
                (float) $pageEl->getAttribute('width'),
                (float) $pageEl->getAttribute('height'),
            );
        }

        return $pages;
    }

    /**
     * @return PdfPage[]
     */
    private function extractWithSmalot(string $filePath): array
    {
        $document = (new SmalotParser())->parseFile($filePath);
        $pages = [];
        $pageNo = 0;
        foreach ($document->getPages() as $page) {
            $pageNo++;
            $details = $page->getDetails();
            $mediaBox = $details['MediaBox'] ?? [0, 0, 595.0, 842.0];
            $pageWidth = (float) ($mediaBox[2] ?? 595.0);
            $pageHeight = (float) ($mediaBox[3] ?? 842.0);

            $words = [];
            foreach ($page->getDataTm() as [$tm, $text]) {
                $text = (string) $text;
                if (trim($text) === '') {
                    continue;
                }
                $fontSize = max(abs((float) ($tm[0] ?? 10)), abs((float) ($tm[3] ?? 10)), 6.0);
                $x = (float) ($tm[4] ?? 0);
                $yTop = $pageHeight - (float) ($tm[5] ?? 0) - $fontSize;
                $charWidth = $fontSize * 0.5;

                // Split the run into words, estimating each word's horizontal extent.
                $cursor = $x;
                foreach (preg_split('/(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $chunk) {
                    $width = mb_strlen($chunk) * $charWidth;
                    if (trim($chunk) !== '') {
                        $words[] = new PdfWord($chunk, $cursor, $yTop, $cursor + $width, $yTop + $fontSize);
                    }
                    $cursor += $width;
                }
            }
            $pages[] = new PdfPage($pageNo, $words, $pageWidth, $pageHeight);
        }

        return $pages;
    }
}
