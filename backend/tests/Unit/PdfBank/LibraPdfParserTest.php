<?php

namespace App\Tests\Unit\PdfBank;

use App\Service\Borderou\Pdf\Bank\LibraPdfParser;
use App\Service\Borderou\Pdf\PdfPage;
use App\Service\Borderou\Pdf\PdfStatementDispatcher;
use PHPUnit\Framework\TestCase;

class LibraPdfParserTest extends TestCase
{
    /** Reconstructed from the layout description; not a real statement. */
    private function page(): PdfPage
    {
        $tr = str_repeat(' ', 68); // top-right box starts at x = 340
        $tl = str_repeat(' ', 6);  // top-left box starts at x = 30

        return WordFixture::fromLayout([
            'LIBRA INTERNET BANK S.A.   Phoenix Tower   www.librabank.ro',
            '',
            $tr . 'Generat la data: 01/07/2024',
            $tr . 'EXEMPLU SRL',
            $tl . 'Moneda: RON',
            'Cont: RO49LIBR0000000000000001',
            '',
            'Sold initial / Opening balance                                                      11,500.00',
            'Data        Descriere                                    Data valutei   Debit       Credit      Sold',
            'Date        Description                                  Value date     Debit       Credit      Balance',
            '03/06/2024  Plata factura 123 FURNIZOR SRL (REF 123456)  03/06/2024     1,250.00                10,250.00',
            '            detalii suplimentare plata',
            '05/06/2024  Incasare CLIENT SRL (OP 98765)               05/06/2024                 3,000.00    13,250.00',
            'Rulaj debitor 1,250.00   Rulaj creditor 3,000.00',
            'Sold final / End balance                                                            13,250.00',
        ], 1, 5.0, 12.0, 44.0);
    }

    public function testDetectsAndParses(): void
    {
        $parser = new LibraPdfParser();
        $page = $this->page();
        self::assertSame(100, $parser->score($page->texts()));

        [$s] = (new PdfStatementDispatcher([$parser]))->parse([$page]);

        self::assertSame('libra', $s->bankKey);
        self::assertSame('RO49LIBR0000000000000001', $s->iban);
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
        self::assertSame('Plata factura 123 FURNIZOR SRL detalii suplimentare plata', $a->description);
        self::assertSame('REF123456', $a->reference);
        self::assertSame('10250.00', $a->balance);

        self::assertSame('2024-06-05', $b->date->format('Y-m-d'));
        self::assertSame('3000.00', $b->credit);
        self::assertSame('Incasare CLIENT SRL', $b->description);
        self::assertSame('OP98765', $b->reference);
        self::assertSame('13250.00', $b->balance);
    }

    public function testScoreIsLowForOtherBanks(): void
    {
        $parser = new LibraPdfParser();
        self::assertSame(0, $parser->score(['ING', 'Bank', 'Extras', 'de', 'cont']));
        self::assertSame(50, $parser->score(['Phoenix', 'Tower']));
        self::assertSame(100, $parser->score(['LIBRA', 'INTERNET', 'BANK', 'S.A.', 'Phoenix', 'Tower']));
    }
}
