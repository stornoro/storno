<?php

namespace App\Tests\Unit\PdfBank;

use App\Service\Borderou\Pdf\Bank\WisePdfParser;
use App\Service\Borderou\Pdf\PdfPage;
use App\Service\Borderou\Pdf\PdfStatementDispatcher;
use PHPUnit\Framework\TestCase;

class WisePdfParserTest extends TestCase
{
    /** Places [offset, text] pairs on one line (byte offsets, 5pt each: WordFixture derives x from byte offsets). */
    private static function row(array $parts): string
    {
        $buf = str_repeat(' ', 120);
        foreach ($parts as [$offset, $text]) {
            $buf = substr($buf, 0, $offset) . $text . substr($buf, $offset + strlen($text));
        }

        return rtrim($buf);
    }

    /** Right-aligns $text so that its right edge (5pt per character) lands at offset $end. */
    private static function ends(int $end, string $text): array
    {
        return [$end - mb_strlen($text), $text];
    }

    /**
     * Reconstructed from the layout description; not a real statement.
     * Amount columns end at offsets 67 (primiți), 84 (trimiși) and 96 (Sold).
     */
    private function page(): PdfPage
    {
        return WordFixture::fromLayout([
            'Wise Europe SA, Rue du Trône 100, Bruxelles',
            'Extras de cont RON',
            self::row([[0, 'Titularul contului'], [35, 'Număr de cont / IBAN']]),
            self::row([[0, 'EXEMPLU SRL'], [35, 'RO49 AAAA 1B31 0075 9384 0000']]),
            self::row([[0, 'RON pe 31 martie 2025'], self::ends(96, '1.250,00 RON')]),
            self::row([[0, 'Descriere'], [54, 'Bani'], self::ends(67, 'primiți'), [71, 'Bani'], self::ends(84, 'trimiși'), self::ends(96, 'Sold')]),
            self::row([[0, 'Plata catre FURNIZOR EXEMPLU SRL'], self::ends(84, '-250,00'), self::ends(96, '1.250,00')]),
            'cu referință FV 12',
            '6 martie 2025  Tranzacție: TRANSFER-123456789  Referință: FV 12',
            self::row([[0, 'Bani primiti de la CLIENT EXEMPLU SRL'], self::ends(67, '500,00'), self::ends(96, '1.500,00')]),
            '5 martie 2025  Tranzacție: TRANSFER-123456780',
            'ref: 0000-0000',
        ]);
    }

    public function testDetectsAndParses(): void
    {
        $parser = new WisePdfParser();
        $page = $this->page();
        self::assertSame(100, $parser->score($page->texts()));

        $dispatcher = new PdfStatementDispatcher([$parser]);
        [$s] = $dispatcher->parse([$page]);

        self::assertSame('wise', $s->bankKey);
        self::assertSame('Wise', $s->bankLabel);
        self::assertSame('RO49AAAA1B31007593840000', $s->iban);
        self::assertSame('RON', $s->currency);
        self::assertSame('EXEMPLU SRL', $s->accountHolder);
        self::assertNull($s->fiscalCode);
        self::assertSame('1000.00', $s->openingBalance);
        self::assertSame('1250.00', $s->closingBalance);
        self::assertSame([], $s->warnings);
        self::assertCount(2, $s->transactions);

        [$a, $b] = $s->transactions;
        self::assertSame('2025-03-05', $a->date->format('Y-m-d'));
        self::assertSame('500.00', $a->credit);
        self::assertSame('0.00', $a->debit);
        self::assertSame('1500.00', $a->balance);
        self::assertSame('TRANSFER-123456780', $a->reference);
        self::assertSame('Bani primiti de la CLIENT EXEMPLU SRL', $a->description);
        self::assertSame('CLIENT EXEMPLU SRL', $a->counterpartyName);

        self::assertSame('2025-03-06', $b->date->format('Y-m-d'));
        self::assertSame('250.00', $b->debit);
        self::assertSame('0.00', $b->credit);
        self::assertSame('1250.00', $b->balance);
        self::assertSame('TRANSFER-123456789', $b->reference);
        self::assertSame('Plata catre FURNIZOR EXEMPLU SRL cu referinta FV 12', $b->description);
        self::assertSame('FURNIZOR EXEMPLU SRL', $b->counterpartyName);
        self::assertCount(3, $b->rawLines);
    }

    public function testScoreIsLowForOtherBanks(): void
    {
        $parser = new WisePdfParser();
        self::assertSame(0, $parser->score(['ING', 'Bank', 'Extras', 'de', 'cont']));
        self::assertSame(50, $parser->score(['Wise', 'Europe', 'SA']));
    }
}
