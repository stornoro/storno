<?php

namespace App\Service\Borderou\Pdf;

final class PdfPage
{
    /**
     * @param PdfWord[] $words
     */
    public function __construct(
        public readonly int $number,
        public readonly array $words,
        public readonly float $width = 0.0,
        public readonly float $height = 0.0,
    ) {}

    /**
     * @return string[]
     */
    public function texts(): array
    {
        return array_map(fn (PdfWord $w) => $w->text, $this->words);
    }
}
