<?php

namespace App\Tests\Unit\PdfBank;

use App\Service\Borderou\Pdf\Bank\UnicreditPdfParser;
use App\Service\Borderou\Pdf\Bank\UnicreditV2PdfParser;
use App\Service\Borderou\Pdf\PdfPage;
use App\Service\Borderou\Pdf\PdfStatementDispatcher;
use PHPUnit\Framework\TestCase;

class UnicreditV2PdfParserTest extends TestCase
{
    /** Reconstructed from the layout description; not a real statement. */
    private function page(): PdfPage
    {
        return WordFixture::fromLayout([
            'UniCredit Bank S.A.   Bulevardul Expoziției nr. 1F   www.unicredit.ro',
            'Denumire companie: DEMO EXEMPLU SRL',
            'IBAN: RO00 BACX 0000 0000 0000 0000',
            'Moneda: RON',
            '',
            'Sumar cont',
            'Sold initial sume debitate sume creditate Sold final',
            '10,000.00 1,250.00 3,400.50 12,150.50',
            '',
            'Tranzactii',
            'Data              Descriere                                  Debit       Credit      Sold(RON)',
            '03 aprilie 2024   PLATA FURNIZOR DEMO SRL                    1,250.00                8,750.00',
            '                  Factura FF-2024-0101 ref 123456789012',
            '05 aprilie 2024   INCASARE CLIENT DEMO SRL                               3,400.50    12,150.50',
            '                  Factura FF-2024-0099',
            'Fondurile disponibile pot fi diferite de sold',
        ]);
    }

    public function testDetectsAndParses(): void
    {
        $parser = new UnicreditV2PdfParser();
        $page = $this->page();
        self::assertSame(100, $parser->score($page->texts()));
        self::assertSame(50, (new UnicreditPdfParser())->score($page->texts()));

        [$s] = (new PdfStatementDispatcher([new UnicreditPdfParser(), $parser]))->parse([$page]);

        self::assertSame('unicredit', $s->bankKey);
        self::assertSame('RO00BACX0000000000000000', $s->iban);
        self::assertSame('RON', $s->currency);
        self::assertSame('DEMO EXEMPLU SRL', $s->accountHolder);
        self::assertNull($s->fiscalCode);
        self::assertSame('10000.00', $s->openingBalance);
        self::assertSame('12150.50', $s->closingBalance);
        self::assertSame([], $s->warnings);
        self::assertCount(2, $s->transactions);

        [$a, $b] = $s->transactions;
        self::assertSame('2024-04-03', $a->date->format('Y-m-d'));
        self::assertSame('1250.00', $a->debit);
        self::assertSame('0.00', $a->credit);
        self::assertSame('PLATA FURNIZOR DEMO SRL Factura FF-2024-0101 ref 123456789012', $a->description);
        self::assertSame('123456789012', $a->reference);
        self::assertSame('8750.00', $a->balance);

        self::assertSame('2024-04-05', $b->date->format('Y-m-d'));
        self::assertSame('3400.50', $b->credit);
        self::assertSame('0.00', $b->debit);
        self::assertSame('INCASARE CLIENT DEMO SRL Factura FF-2024-0099', $b->description);
        self::assertNull($b->reference);
        self::assertSame('12150.50', $b->balance);
    }

    public function testConsecutiveSingleLineRowsStaySeparate(): void
    {
        $page = WordFixture::fromLayout([
            'UniCredit Bank S.A.   Bulevardul Expoziției nr. 1F   www.unicredit.ro',
            'IBAN: RO00 BACX 0000 0000 0000 0000',
            '',
            '',
            '',
            'Tranzactii',
            'Data              Descriere                                  Debit       Credit      Sold(RON)',
            '03 aprilie 2024   Comision administrare cont                 10.00                   990.00',
            '04 aprilie 2024   Comision SMS                               5.00                    985.00',
            '05 aprilie 2024   INCASARE CLIENT DEMO SRL                               100.00      1,085.00',
        ]);
        [$s] = (new UnicreditV2PdfParser())->parse([$page]);
        self::assertCount(3, $s->transactions);
        self::assertSame('Comision SMS', $s->transactions[1]->description);
        self::assertSame('5.00', $s->transactions[1]->debit);
    }

    public function testScoreIsLowForOtherBanks(): void
    {
        $parser = new UnicreditV2PdfParser();
        self::assertSame(0, $parser->score(['ING', 'Bank', 'Extras', 'de', 'cont']));
        self::assertSame(50, $parser->score(['UniCredit', 'Bank', 'S.A.', 'www.unicredit.ro']));
    }
}
