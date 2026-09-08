<?php

namespace App\Service\Borderou\Pdf;

/**
 * One word of text on a PDF page, with its bounding box in points.
 * Coordinates use a top-left origin: y grows downwards (same as pdftotext -bbox-layout).
 */
final class PdfWord
{
    public function __construct(
        public readonly string $text,
        public readonly float $x0,
        public readonly float $y0,
        public readonly float $x1,
        public readonly float $y1,
    ) {}

    public function centerX(): float
    {
        return ($this->x0 + $this->x1) / 2;
    }

    public function centerY(): float
    {
        return ($this->y0 + $this->y1) / 2;
    }

    public function height(): float
    {
        return $this->y1 - $this->y0;
    }
}
