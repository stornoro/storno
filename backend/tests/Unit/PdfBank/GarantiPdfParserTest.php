<?php

namespace App\Tests\Unit\PdfBank;

use App\Service\Borderou\Pdf\Bank\GarantiPdfParser;
use App\Service\Borderou\Pdf\PdfPage;
use App\Service\Borderou\Pdf\PdfStatementDispatcher;
use PHPUnit\Framework\TestCase;

class GarantiPdfParserTest extends TestCase
{
    /** Reconstructed from the layout description; not a real statement. */
    private function page(): PdfPage
    {
        return WordFixture::fromLayout([
            'Garanti Bank S.A.   Fabrica de Glucoză nr. 5   www.garantibbva.ro',
            'Nume client : EXEMPLU SRL',
            'Cod IBAN : RO49AAAA1B31007593840000',
            'Valuta : RON',
            'Extras de cont',
            'Sold inițial : 11.500,00 RON',
            'Data        Detalii                                                             Suma        Sold',
            '            PLATA FURNIZOR FACTURA 123',
            '03/06/2024  Ordonator: EXEMPLU SRL Referinta: 2024-06-03-10.15.22.123456       -1.250,00   10.250,00',
            '            INCASARE CLIENT SRL',
            '05/06/2024  Referinta: 1234567890123                                            3.000,00    13.250,00',
            'Rulaj total perioada Soldul final : 13.250,00 RON',
        ]);
    }

    public function testDetectsAndParses(): void
    {
        $parser = new GarantiPdfParser();
        $page = $this->page();
        self::assertSame(100, $parser->score($page->texts()));

        [$s] = (new PdfStatementDispatcher([$parser]))->parse([$page]);

        self::assertSame('garanti', $s->bankKey);
        self::assertSame('RO49AAAA1B31007593840000', $s->iban);
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
        self::assertSame('0.00', $a->credit);
        self::assertSame('PLATA FURNIZOR FACTURA 123 Ordonator: EXEMPLU SRL Referinta: 2024-06-03-10.15.22.123456', $a->description);
        self::assertSame('2024-06-03-10.15.22.123456', $a->reference);
        self::assertSame('10250.00', $a->balance);

        self::assertSame('2024-06-05', $b->date->format('Y-m-d'));
        self::assertSame('3000.00', $b->credit);
        self::assertSame('0.00', $b->debit);
        self::assertSame('INCASARE CLIENT SRL Referinta: 1234567890123', $b->description);
        self::assertSame('1234567890123', $b->reference);
        self::assertSame('13250.00', $b->balance);
    }

    public function testRepeatedHeaderOnSecondPageIsSkipped(): void
    {
        $page1 = $this->page();
        $page2 = WordFixture::fromLayout([
            'Data        Detalii                                                             Suma        Sold',
            '            COMISION ADMINISTRARE CONT',
            '06/06/2024  Referinta: 1234567890124                                            -10,00      13.240,00',
            'Rulaj total perioada Soldul final : 13.240,00 RON',
        ], 2);
        [$s] = (new GarantiPdfParser())->parse([$page1, $page2]);
        self::assertSame([], $s->warnings);
        self::assertCount(3, $s->transactions);
        self::assertSame('COMISION ADMINISTRARE CONT Referinta: 1234567890124', $s->transactions[2]->description);
        self::assertSame('10.00', $s->transactions[2]->debit);
        self::assertSame('13240.00', $s->closingBalance);
    }

    public function testScoreIsLowForOtherBanks(): void
    {
        $parser = new GarantiPdfParser();
        self::assertSame(0, $parser->score(['ING', 'Bank', 'Extras', 'de', 'cont']));
        self::assertSame(50, $parser->score(['Garanti', 'Bank', 'S.A.']));
        self::assertSame(100, $parser->score(['Garanti', 'Bank', 'S.A.', 'Fabrica', 'de', 'Glucoză']));
    }
}
