<?php

namespace App\Tests\Unit\PdfBank;

use App\Service\Borderou\Pdf\Bank\BancaTransilvaniaPdfParser;
use App\Service\Borderou\Pdf\PdfStatementDispatcher;
use PHPUnit\Framework\TestCase;

class BancaTransilvaniaPdfParserTest extends TestCase
{
    /** Reconstructed from the layout description; not a real statement. */
    private function page(): \App\Service\Borderou\Pdf\PdfPage
    {
        return WordFixture::fromLayout([
            'BANCA TRANSILVANIA S.A.                          Info clienti  BT24@bancatransilvania.ro',
            'EXTRAS CONT   Numarul: 7 din 01/02/2024 - 29/02/2024',
            'EXEMPLU COM SRL                                Client: 123456',
            '                                               CUI: 12345678',
            'CONT 106RONCRT0000123456    RON Cod IBAN: RO78BTRL0000999900001234   Valuta RON',
            '',
            'Data          Descriere                                              Debit          Credit',
            '              SOLD ANTERIOR 01/02/2024:                                              1,000.00',
            '01/02/2024    Plata factura F1234 REF: ABC123DEF456                  250.00',
            '              Beneficiar: FURNIZOR TEST SRL',
            '              RULAJ ZI                                               250.00',
            '              SOLD FINAL ZI                                                         750.00',
            '05/02/2024    Incasare client REF: XYZ987                                           1,200.50',
            '              Platitor: CLIENT TEST SRL',
            '              SOLD FINAL CONT:                                                     1,950.50',
            'www.bancatransilvania.ro   SWIFT: BTRLRO22          / 1 /',
        ]);
    }

    public function testDetectsAndParses(): void
    {
        $parser = new BancaTransilvaniaPdfParser();
        $page = $this->page();
        self::assertSame(100, $parser->score($page->texts()));

        $dispatcher = new PdfStatementDispatcher([$parser]);
        [$s] = $dispatcher->parse([$page]);

        self::assertSame('bt', $s->bankKey);
        self::assertSame('RO78BTRL0000999900001234', $s->iban);
        self::assertSame('RON', $s->currency);
        self::assertSame('12345678', $s->fiscalCode);
        self::assertSame('EXEMPLU COM SRL', $s->accountHolder);
        self::assertSame('1000.00', $s->openingBalance);
        self::assertSame('1950.50', $s->closingBalance);
        self::assertSame([], $s->warnings);
        self::assertCount(2, $s->transactions);

        [$a, $b] = $s->transactions;
        self::assertSame('2024-02-01', $a->date->format('Y-m-d'));
        self::assertSame('250.00', $a->debit);
        self::assertSame('ABC123DEF456', $a->reference);
        self::assertSame('Plata factura F1234 Beneficiar: FURNIZOR TEST SRL', $a->description);
        self::assertSame('750.00', $a->balance);

        self::assertSame('2024-02-05', $b->date->format('Y-m-d'));
        self::assertSame('1200.50', $b->credit);
        self::assertSame('XYZ987', $b->reference);
        self::assertSame('Incasare client Platitor: CLIENT TEST SRL', $b->description);
        self::assertSame('1950.50', $b->balance);
    }

    public function testScoreIsLowForOtherBanks(): void
    {
        $parser = new BancaTransilvaniaPdfParser();
        self::assertSame(0, $parser->score(['ING', 'Bank', 'Extras', 'de', 'cont']));
        self::assertSame(50, $parser->score(['Banca', 'Transilvania']));
    }
}
