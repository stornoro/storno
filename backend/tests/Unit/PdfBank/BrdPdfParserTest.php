<?php

namespace App\Tests\Unit\PdfBank;

use App\Service\Borderou\Pdf\Bank\BrdPdfParser;
use App\Service\Borderou\Pdf\PdfStatementDispatcher;
use PHPUnit\Framework\TestCase;

/** Reconstructed BRD classic layout; placeholder names and synthetic IBAN. */
class BrdPdfParserTest extends TestCase
{
    private function page(): \App\Service\Borderou\Pdf\PdfPage
    {
        return WordFixture::fromLayout([
            ' BRD-Groupe Societe Generale S.A.',
            ' Bd. Ion Mihalache nr. 1-7, Sector 1',
            ' Domicilierea contului: Agentia Test',
            '',
            '',
            '                                                                Acest extras este valabil fara semnatura',
            '                                                                EXEMPLU COM SRL',
            '                                                                CNP/CUI: 12345678',
            '',
            '',
            '',
            '',
            '',
            '',
            '      Cont curent RON RO07BRDE0000999900001234',
            '',
            ' Data oper.   Descriere operatiune                        Debit         Credit          Data valutei',
            ' (1)          (2)                                         (3)           (4)             (5)',
            '              Sold initial                                              1.000,00',
            ' 01/02/2024   Plata furnizor OP 15 factura F1234          250,00                        01/02/2024',
            '              FURNIZOR TEST SRL',
            ' 05/02/2024   Incasare client OPH ABC123                                1.200,50        05/02/2024',
            '              Sold final                                                1.950,50',
        ]);
    }

    public function testDetectsAndParses(): void
    {
        $parser = new BrdPdfParser();
        $page = $this->page();
        self::assertSame(100, $parser->score($page->texts()));

        [$s] = (new PdfStatementDispatcher([$parser]))->parse([$page]);
        self::assertSame('brd', $s->bankKey);
        self::assertSame('RO07BRDE0000999900001234', $s->iban);
        self::assertSame('RON', $s->currency);
        self::assertSame('EXEMPLU COM SRL', $s->accountHolder);
        self::assertSame('12345678', $s->fiscalCode);
        self::assertSame('1000.00', $s->openingBalance);
        self::assertSame('1950.50', $s->closingBalance);
        self::assertSame([], $s->warnings);
        self::assertCount(2, $s->transactions);
        [$a, $b] = $s->transactions;
        self::assertSame('2024-02-01', $a->date->format('Y-m-d'));
        self::assertSame('250.00', $a->debit);
        self::assertSame('OP15', $a->reference);
        self::assertSame('Plata furnizor factura F1234 FURNIZOR TEST SRL', $a->description);
        self::assertSame('2024-02-05', $b->date->format('Y-m-d'));
        self::assertSame('1200.50', $b->credit);
        self::assertSame('OPHABC123', $b->reference);
        self::assertSame('1950.50', $b->balance);
    }

    public function testScoreNeedsAllThreeMarkers(): void
    {
        self::assertSame(50, (new BrdPdfParser())->score(['BRD-Groupe', 'Societe', 'Generale', 'S.A.']));
        self::assertSame(0, (new BrdPdfParser())->score(['ING', 'Bank']));
    }
}
