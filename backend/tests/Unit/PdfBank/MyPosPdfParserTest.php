<?php

namespace App\Tests\Unit\PdfBank;

use App\Service\Borderou\Pdf\Bank\MyPosPdfParser;
use App\Service\Borderou\Pdf\PdfPage;
use App\Service\Borderou\Pdf\PdfStatementDispatcher;
use PHPUnit\Framework\TestCase;

class MyPosPdfParserTest extends TestCase
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

    /**
     * Reconstructed from the layout description; not a real statement.
     * The first six rows sit in the top margin (outside the 60 < Top < 730 table band),
     * so "Sold la deschidere" lands at flat-line index 8 as the layout expects.
     */
    private function page(): PdfPage
    {
        return WordFixture::fromLayout([
            "myPOS Ltd, 12 St. Stephen\u{2019}s Green, Dublin 2, Ireland",
            'Registration number 700880, Ireland',
            'Extras de cont',
            'Perioada: 01.03.2025 - 31.03.2025',
            'Pagina 1 din 1',
            'myPOS Payments Ltd',
            self::row([[6, 'Nume: EXEMPLU SRL'], [40, 'IBAN: RO49AAAA1B31007593840000']]),   // band index 0, holder box
            self::row([[6, 'Moneda: RON']]),                                                  // 1
            self::row([[6, 'Adresa: Str. Exemplu nr. 1']]),                                   // 2
            self::row([[6, 'Cont: Business']]),                                               // 3
            'Tip cont: Curent',                                                               // 4
            'Client: 12345',                                                                  // 5
            'Extras generat: 01.04.2025',                                                     // 6
            'Tranzactii',                                                                     // 7
            self::row([[0, 'Sold la deschidere:'], [71, '1,000.00']]),                        // 8
            self::row([[0, 'Sold la închidere:'], [71, '1,025.30']]),                         // 9
            self::row([[0, 'Data valutei'], [19, 'Comandați prin'], [35, 'Bacșis'], [43, 'Descriere'], [72, 'Curs de schimb'], [88, 'Debit'], [97, 'Credit']]),
            self::row([[0, '06.03.2025 09:10'], [19, 'Transfer'], [43, 'Comision decontare'], [88, '20.00']]),
            self::row([[0, '05.03.2025 14:22'], [19, 'POS'], [35, '0.00'], [43, 'Plata card 1234 Example Shop'], [72, '1.0000'], [97, '45.30']]),
            self::row([[43, 'Terminal 12345']]),
        ], 1, 5.0, 12.0, 50.0);
    }

    public function testDetectsAndParses(): void
    {
        $parser = new MyPosPdfParser();
        $page = $this->page();
        self::assertSame(100, $parser->score($page->texts()));

        $dispatcher = new PdfStatementDispatcher([$parser]);
        [$s] = $dispatcher->parse([$page]);

        self::assertSame('mypos', $s->bankKey);
        self::assertSame('myPOS', $s->bankLabel);
        self::assertSame('RO49AAAA1B31007593840000', $s->iban);
        self::assertSame('RON', $s->currency);
        self::assertSame('EXEMPLU SRL', $s->accountHolder);
        self::assertNull($s->fiscalCode);
        self::assertSame('1000.00', $s->openingBalance);
        self::assertSame('1025.30', $s->closingBalance);
        self::assertSame([], $s->warnings);
        self::assertCount(2, $s->transactions);

        [$a, $b] = $s->transactions;
        self::assertSame('2025-03-05', $a->date->format('Y-m-d'));
        self::assertSame('45.30', $a->credit);
        self::assertSame('0.00', $a->debit);
        self::assertSame('1045.30', $a->balance);
        self::assertNull($a->reference);
        self::assertSame('Plata card 1234 Example Shop Terminal 12345', $a->description);
        self::assertCount(2, $a->rawLines);

        self::assertSame('2025-03-06', $b->date->format('Y-m-d'));
        self::assertSame('20.00', $b->debit);
        self::assertSame('0.00', $b->credit);
        self::assertSame('1025.30', $b->balance);
        self::assertNull($b->reference);
        self::assertSame('Comision decontare', $b->description);
    }

    public function testScoreIsLowForOtherBanks(): void
    {
        $parser = new MyPosPdfParser();
        self::assertSame(0, $parser->score(['ING', 'Bank', 'Extras', 'de', 'cont']));
        self::assertSame(50, $parser->score(['myPOS', 'Ltd']));
    }
}
