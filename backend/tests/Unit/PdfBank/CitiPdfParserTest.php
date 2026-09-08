<?php

namespace App\Tests\Unit\PdfBank;

use App\Service\Borderou\Pdf\Bank\CitiPdfParser;
use App\Service\Borderou\Pdf\PdfPage;
use App\Service\Borderou\Pdf\PdfStatementDispatcher;
use PHPUnit\Framework\TestCase;

class CitiPdfParserTest extends TestCase
{
    /** Reconstructed from the layout description; not a real statement. */
    private function page(): PdfPage
    {
        return WordFixture::fromLayout([
            'Citibank                                     Tiriac Tower, Bucuresti',
            'EXTRAS DE CONT',
            'TITULAR CONT : EXEMPLU SRL',
            'NUMĂR CONT : RO49CITI0000000000000001',
            'VALUTĂ : RON',
            '',
            'DATA         DATA VALUTEI  DESCRIERE                                                    DEBIT      CREDIT     SOLD CURENT',
            '03-Jun-2024  03-Jun-2024   Balanta de deschidere                                                              11,500.00',
            '03-Jun-2024  03-Jun-2024   TRANSFER Detalii de plata: Factura 123/2024                  1,250.00              10,250.00',
            '                           Beneficiar: FURNIZOR SRL Custom Reference: 4567890',
            '05-Jun-2024  05-Jun-2024   INCASARE Din ordinul: CLIENT SRL E2E Reference: NOTPROVIDED             3,000.00   13,250.00',
            '30-Jun-2024  30-Jun-2024   Balanta de inchidere                                                               13,250.00',
            'Numar total de debite  Numar total de credite  Total debite  Total credite',
            '1  1  RON  1,250.00  3,000.00',
            'Citibank Europe plc, Dublin - Sucursala Romania, 82-94 Buzesti',
            '05-Jun-2024  05-Jun-2024   linie de subsol ignorata                                                9,999.00   99,999.00',
        ]);
    }

    public function testDetectsAndParses(): void
    {
        $parser = new CitiPdfParser();
        $page = $this->page();
        self::assertSame(100, $parser->score($page->texts()));

        $dispatcher = new PdfStatementDispatcher([$parser]);
        [$s] = $dispatcher->parse([$page]);

        self::assertSame('citi', $s->bankKey);
        self::assertSame('Citibank Europe', $s->bankLabel);
        self::assertSame('RO49CITI0000000000000001', $s->iban);
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
        self::assertSame('4567890', $a->reference);
        self::assertSame('Detalii de plata: Factura 123 2024 Beneficiar: FURNIZOR SRL TRANSFER', $a->description);
        self::assertSame('FURNIZOR SRL', $a->counterpartyName);
        self::assertSame('10250.00', $a->balance);

        self::assertSame('2024-06-05', $b->date->format('Y-m-d'));
        self::assertSame('3000.00', $b->credit);
        self::assertNull($b->reference);
        self::assertSame('Din ordinul: CLIENT SRL INCASARE', $b->description);
        self::assertNull($b->counterpartyName);
        self::assertSame('13250.00', $b->balance);
    }

    public function testScoreIsLowForOtherBanks(): void
    {
        $parser = new CitiPdfParser();
        self::assertSame(0, $parser->score(['ING', 'Bank', 'Extras', 'de', 'cont']));
        self::assertSame(50, $parser->score(['Citibank', 'Romania']));
        self::assertSame(100, $parser->score(['Citibank', 'Europe', 'plc']));
    }
}
