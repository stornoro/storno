<?php

namespace App\Tests\Unit\PdfBank;

use App\Service\Borderou\Pdf\Bank\RaiffeisenPdfParser;
use App\Service\Borderou\Pdf\PdfPage;
use App\Service\Borderou\Pdf\PdfStatementDispatcher;
use PHPUnit\Framework\TestCase;

class RaiffeisenPdfParserTest extends TestCase
{
    /** Reconstructed from the layout description; not a real statement. */
    private function page(): PdfPage
    {
        return WordFixture::fromLayout([
            'Raiffeisen Bank S.A.  Calea Floreasca Nr. 246 D  www.raiffeisen.ro',
            'Dată generare extras: 02.05.2024',
            'DEMO EXEMPLU SRL',
            'Cod unic: 12345678',
            'Cod IBAN: RO00 RZBR 0000 0000 0000 0000',
            'Valuta: LEI',
            '',
            'Sold initial rulaj debitor rulaj creditor Sold final',
            '10,000.00 1,250.00 3,400.50 12,150.50',
            '',
            'Data          Data          Descrierea tranzacției          Referinta         Debit       Credit    Sold',
            'înregistrare  tranzacției',
            '03.04.2024    03.04.2024    Plata factura FF-2024-0101 95400000000000123456   1,250.00',
            '                            Beneficiar FURNIZOR DEMO SRL',
            '                            Soldul zilei 03.04.2024                                                   8,750.00',
            '05.04.2024    05.04.2024    Incasare CLIENT DEMO SRL 95400000000000123457               3,400.50',
            'Sold final                                                                                          12,150.50',
        ]);
    }

    public function testDetectsAndParses(): void
    {
        $parser = new RaiffeisenPdfParser();
        $page = $this->page();
        self::assertSame(100, $parser->score($page->texts()));

        [$s] = (new PdfStatementDispatcher([$parser]))->parse([$page]);

        self::assertSame('raiffeisen', $s->bankKey);
        self::assertSame('RO00RZBR0000000000000000', $s->iban);
        self::assertSame('RON', $s->currency);
        self::assertSame('DEMO EXEMPLU SRL', $s->accountHolder);
        self::assertSame('12345678', $s->fiscalCode);
        self::assertSame('10000.00', $s->openingBalance);
        self::assertSame('12150.50', $s->closingBalance);
        self::assertSame([], $s->warnings);
        self::assertCount(2, $s->transactions);

        [$a, $b] = $s->transactions;
        self::assertSame('2024-04-03', $a->date->format('Y-m-d'));
        self::assertSame('1250.00', $a->debit);
        self::assertSame('0.00', $a->credit);
        self::assertSame('95400000000000123456', $a->reference);
        self::assertSame('Plata factura FF-2024-0101 Beneficiar FURNIZOR DEMO SRL', $a->description);
        self::assertSame('8750.00', $a->balance);

        self::assertSame('2024-04-05', $b->date->format('Y-m-d'));
        self::assertSame('3400.50', $b->credit);
        self::assertSame('95400000000000123457', $b->reference);
        self::assertSame('Incasare CLIENT DEMO SRL', $b->description);
        self::assertSame('12150.50', $b->balance);
    }

    public function testScoreIsLowForOtherBanks(): void
    {
        $parser = new RaiffeisenPdfParser();
        self::assertSame(0, $parser->score(['ING', 'Bank', 'Extras', 'de', 'cont']));
        self::assertSame(50, $parser->score(['Raiffeisen', 'Bank', 'S.A.']));
        self::assertSame(100, $parser->score(['Raiffeisen', 'Bank', 'S.A.', 'Calea', 'Floreasca', 'Nr.', '246', 'D']));
    }
}
