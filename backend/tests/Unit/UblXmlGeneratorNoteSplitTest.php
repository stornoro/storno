<?php

namespace App\Tests\Unit;

use App\Service\Anaf\UblXmlGenerator;
use App\Service\ExchangeRateService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\FilterCollection;
use PHPUnit\Framework\TestCase;

/**
 * [BR-RO-L300] a cbc:Note may hold at most 300 characters; a longer invoice
 * comment is split into several notes (max 20, [BR-RO-A020]) instead of being
 * rejected by ANAF.
 */
class UblXmlGeneratorNoteSplitTest extends TestCase
{
    private UblXmlGenerator $generator;

    protected function setUp(): void
    {
        $filters = $this->createMock(FilterCollection::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getFilters')->willReturn($filters);
        $this->generator = new UblXmlGenerator($this->createMock(ExchangeRateService::class), $em);
    }

    public function testShortNoteStaysWhole(): void
    {
        self::assertSame(['Plata in 30 de zile.'], $this->generator->splitNote("Plata in 30 de zile.\n"));
    }

    public function testLongNoteIsSplitOnWordBoundariesAndKeepsEveryWord(): void
    {
        $words = [];
        for ($i = 1; $i <= 120; $i++) {
            $words[] = 'cuvânt' . $i; // multibyte, 7–9 chars each
        }
        $text = implode(' ', $words);
        $chunks = $this->generator->splitNote($text);

        self::assertGreaterThan(1, count($chunks));
        foreach ($chunks as $chunk) {
            self::assertLessThanOrEqual(UblXmlGenerator::NOTE_MAX_LENGTH, mb_strlen($chunk));
            self::assertSame(trim($chunk), $chunk);
        }
        self::assertSame($text, implode(' ', $chunks));
    }

    public function testWordLongerThanLimitIsHardCut(): void
    {
        $chunks = $this->generator->splitNote(str_repeat('a', 650));
        self::assertSame([300, 300, 50], array_map('mb_strlen', $chunks));
    }

    public function testNoteCountIsCapped(): void
    {
        $chunks = $this->generator->splitNote(str_repeat('x', 300 * 25));
        self::assertCount(UblXmlGenerator::NOTE_MAX_COUNT, $chunks);
    }
}
