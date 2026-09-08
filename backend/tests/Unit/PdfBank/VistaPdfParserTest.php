<?php

namespace App\Tests\Unit\PdfBank;

use App\Service\Borderou\Pdf\Bank\VistaPdfParser;
use App\Service\Borderou\Pdf\PdfPage;
use App\Service\Borderou\Pdf\PdfStatementDispatcher;
use PHPUnit\Framework\TestCase;

class VistaPdfParserTest extends TestCase
{
    /** Reconstructed from the layout description; not a real statement. Rows are separated by a blank line (> 17pt gap). */
    private function page(): PdfPage
    {
        return WordFixture::fromLayout([
            'VISTA BANK ROMANIA  SWIFT: EGNAROBX',
            '                                   EXEMPLU SRL',
            '                                   Adresa: Str. Exemplu 1',
            'IBAN RO49EGNA0000000000000001      Moneda RON',
            'Sold initial 11,500.00',
            '',
            'Data tranzactiei',
            'Data valutei      Descriere         Referinta     Debit       Credit      Sold',
            '03.06.2024      Plata factura 123   REF123456     1,250.00                10,250.00',
            '03.06.2024      FURNIZOR SRL',
            '',
            '05.06.2024      Incasare CLIENT SRL OP98765                   3,000.00    13,250.00',
            '05.06.2024',
            '',
            'Rulaj debitor          Rulaj creditor          Sold final',
            '                                                  1,250.00    3,000.00    13,250.00',
            'Vista Bank (Romania) S.A. Bucuresti',
            '07.06.2024      linie de subsol ignorata                      9,999.00    99,999.00',
        ]);
    }

    public function testDetectsAndParses(): void
    {
        $parser = new VistaPdfParser();
        $page = $this->page();
        self::assertSame(100, $parser->score($page->texts()));

        $dispatcher = new PdfStatementDispatcher([$parser]);
        [$s] = $dispatcher->parse([$page]);

        self::assertSame('vista', $s->bankKey);
        self::assertSame('Vista Bank', $s->bankLabel);
        self::assertSame('RO49EGNA0000000000000001', $s->iban);
        self::assertSame('RON', $s->currency);
        self::assertSame('EXEMPLU SRL', $s->accountHolder);
        self::assertNull($s->fiscalCode);
        self::assertSame('11500.00', $s->openingBalance);
        self::assertSame('13250.00', $s->closingBalance);
        self::assertSame([], $s->warnings);
        self::assertCount(2, $s->transactions);

        [$a, $b] = $s->transactions;
        self::assertSame('2024-06-03', $a->date->format('Y-m-d'));
        self::assertSame('1250.00', $a->debit);
        self::assertSame('REF123456', $a->reference);
        self::assertSame('Plata factura 123 FURNIZOR SRL', $a->description);
        self::assertSame('10250.00', $a->balance);

        self::assertSame('2024-06-05', $b->date->format('Y-m-d'));
        self::assertSame('3000.00', $b->credit);
        self::assertSame('OP98765', $b->reference);
        self::assertSame('Incasare CLIENT SRL', $b->description);
        self::assertSame('13250.00', $b->balance);
    }

    public function testPrivateUseGlyphsAreResolved(): void
    {
        // WordFixture derives x from byte offsets: each 3-byte PUA glyph shifts the rest of the
        // line by two columns, so the description is kept short enough for REF1 to stay at column 36.
        $page = WordFixture::fromLayout([
            'VISTA BANK ROMANIA  SWIFT: EGNAROBX',
            'Sold initial 100.00',
            'Data valutei      Descriere         Referinta     Debit       Credit      Sold',
            "03.06.2024      Pl fac\u{E001}une 1\u{E002}2  REF1          50.00                   50.00",
            '',
            'Rulaj debitor          Rulaj creditor          Sold final',
            '                                                  50.00        0.00        50.00',
        ]);
        [$s] = (new VistaPdfParser())->parse([$page]);
        self::assertSame([], $s->warnings);
        self::assertSame('Pl factiune 1/2', $s->transactions[0]->description);
        self::assertSame('REF1', $s->transactions[0]->reference);
        self::assertSame('50.00', $s->transactions[0]->debit);
    }

    public function testScoreIsZeroForOtherBanks(): void
    {
        $parser = new VistaPdfParser();
        self::assertSame(0, $parser->score(['ING', 'Bank', 'Extras', 'de', 'cont']));
        self::assertSame(100, $parser->score(['www.vistabank.ro']));
    }
}
