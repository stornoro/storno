<?php

namespace App\Service\Borderou\Pdf;

/**
 * Picks the bank parser whose score on page 1 is highest, then parses.
 */
class PdfStatementDispatcher
{
    public const THRESHOLD = 60;

    /** @var PdfStatementParserInterface[] */
    private array $parsers;

    /**
     * @param iterable<PdfStatementParserInterface> $parsers
     */
    public function __construct(iterable $parsers)
    {
        $this->parsers = is_array($parsers) ? $parsers : iterator_to_array($parsers, false);
    }

    /**
     * @param PdfPage[] $pages
     * @return array{parser: PdfStatementParserInterface, score: int}|null
     */
    public function select(array $pages): ?array
    {
        $first = $pages[0] ?? null;
        if (!$first) {
            return null;
        }
        $texts = $first->texts();

        $best = null;
        $bestScore = -1;
        foreach ($this->parsers as $parser) {
            $score = $parser->score($texts);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $parser;
            }
        }

        if (!$best || $bestScore < self::THRESHOLD) {
            return null;
        }

        return ['parser' => $best, 'score' => $bestScore];
    }

    /**
     * @param PdfPage[] $pages
     * @return PdfStatement[]
     */
    public function parse(array $pages, ?string $sourcePath = null): array
    {
        $selected = $this->select($pages);
        if (!$selected) {
            throw new PdfStatementNotRecognizedException();
        }
        $parser = $selected['parser'];
        // Parsers that read data outside the text layer (e.g. the XML attached to
        // Treasury statements) need the file itself.
        if ($sourcePath !== null && method_exists($parser, 'setSourcePath')) {
            $parser->setSourcePath($sourcePath);
        }

        return $parser->parse($pages);
    }

    /**
     * @return array<string, string> bankKey => label
     */
    public function supportedBanks(): array
    {
        $out = [];
        foreach ($this->parsers as $parser) {
            $out[$parser->getBankKey()] = $parser->getBankLabel();
        }

        return $out;
    }
}
