<?php

namespace App\Tests\Unit\PdfBank;

use App\Service\Borderou\Pdf\Bank\VivaPdfParser;
use App\Service\Borderou\Pdf\PdfPage;
use App\Service\Borderou\Pdf\PdfStatementDispatcher;
use PHPUnit\Framework\TestCase;

class VivaPdfParserTest extends TestCase
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

    /** Reconstructed from the layout description; not a real statement. */
    private function page(): PdfPage
    {
        return WordFixture::fromLayout([
            self::row([[0, 'VIVABANK S.A.'], [59, 'EXEMPLU SRL']]),
            'Cod fiscal: EL999846755',
            self::row([[0, 'Numar cont: 1234567890'], [32, 'Valuta: RON']]),
            self::row([[0, 'Data tranzacției'], [22, 'Data valorii'], [38, 'Descriere'], [70, 'Valoare'], [82, 'Balanță']]),
            self::row([[0, 'Soldul depus'], [82, '1.000,00']]),
            self::row([[38, 'Incasari carduri']]),
            self::row([[0, '05.03.2025'], [22, '05.03.2025'], [38, 'POS 1234 Example Shop'], [71, '250,00'], [82, '1.250,00']]),
            self::row([[38, 'Comision decontare']]),
            self::row([[0, '06.03.2025'], [22, '06.03.2025'], [38, 'lot 778899'], [71, '-12,50'], [82, '1.237,50']]),
            self::row([[0, 'Soldul reportat'], [82, '1.237,50']]),
        ]);
    }

    public function testDetectsAndParses(): void
    {
        $parser = new VivaPdfParser();
        $page = $this->page();
        self::assertSame(100, $parser->score($page->texts()));

        $dispatcher = new PdfStatementDispatcher([$parser]);
        [$s] = $dispatcher->parse([$page]);

        self::assertSame('viva', $s->bankKey);
        self::assertSame('Viva.com (Viva Wallet)', $s->bankLabel);
        self::assertSame('1234567890', $s->iban);
        self::assertSame('RON', $s->currency);
        self::assertSame('EXEMPLU SRL', $s->accountHolder);
        self::assertNull($s->fiscalCode);
        self::assertSame('1000.00', $s->openingBalance);
        self::assertSame('1237.50', $s->closingBalance);
        self::assertSame([], $s->warnings);
        self::assertCount(2, $s->transactions);

        [$a, $b] = $s->transactions;
        self::assertSame('2025-03-05', $a->date->format('Y-m-d'));
        self::assertSame('2025-03-05', $a->valueDate?->format('Y-m-d'));
        self::assertSame('250.00', $a->credit);
        self::assertSame('0.00', $a->debit);
        self::assertSame('1250.00', $a->balance);
        self::assertNull($a->reference);
        self::assertSame('Incasari carduri POS 1234 Example Shop', $a->description);
        self::assertCount(2, $a->rawLines);

        self::assertSame('2025-03-06', $b->date->format('Y-m-d'));
        self::assertSame('12.50', $b->debit);
        self::assertSame('0.00', $b->credit);
        self::assertSame('1237.50', $b->balance);
        self::assertSame('Comision decontare lot 778899', $b->description);
    }

    public function testDoubledGlyphsAreCollapsed(): void
    {
        $parser = new VivaPdfParser();
        $m = new \ReflectionMethod($parser, 'undouble');
        self::assertSame('Soldul', $m->invoke($parser, 'SSoolldduull'));
        self::assertSame('1.000,00', $m->invoke($parser, '11..000000,,0000'));
        self::assertSame('Soldul', $m->invoke($parser, 'Soldul'));
        self::assertSame('SSoollddull', $m->invoke($parser, 'SSoollddull'));
        self::assertSame('Data', $m->invoke($parser, 'DDaattaa'));
        self::assertSame('Detalii', $m->invoke($parser, 'Detalii'));
        self::assertSame('ab', $m->invoke($parser, 'ab'));
    }

    public function testScoreIsLowForOtherBanks(): void
    {
        $parser = new VivaPdfParser();
        self::assertSame(0, $parser->score(['ING', 'Bank', 'Extras', 'de', 'cont']));
        self::assertSame(50, $parser->score(['VIVABANK', 'S.A.']));
    }
}
