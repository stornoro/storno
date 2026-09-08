<?php

namespace App\Tests\Unit\PdfBank;

use App\Service\Borderou\Pdf\Bank\PatriaPdfParser;
use App\Service\Borderou\Pdf\PdfPage;
use App\Service\Borderou\Pdf\PdfStatementDispatcher;
use PHPUnit\Framework\TestCase;

class PatriaPdfParserTest extends TestCase
{
    /** Reconstructed from the layout description; not a real statement. */
    private function page(): PdfPage
    {
        return WordFixture::fromLayout([
            'Patria Bank S.A.                        Globalworth Plaza, Bucuresti',
            'EXTRAS DE CONT / ACCOUNT STATEMENT',
            'Customer name: EXEMPLU SRL',
            'Registration Number: 12345678',
            'Account number: RO49 AAAA 1B31 0075 9384 0000',
            'Currency: RON',
            'Sold initial / Opening balance 11,500.00',
            '',
            'Data valutei  Data decontarii  Referinta                                  Tranzactie debit  Tranzactie credit  Sold',
            'Value date    Settlement date  Reference                                  Debit             Credit             Balance',
            '03.06.2024    03.06.2024       123456789:Plata factura 123 FURNIZOR SRL   1,250.00                             10,250.00',
            '                               detalii suplimentare',
            '05.06.2024    05.06.2024       987654321:Incasare CLIENT SRL                                3,000.00           13,250.00',
            'Sold / Balance                                                                                                  13,250.00',
            'Datele inscrise in acest extras sunt conforme cu evidentele bancii',
            '05.06.2024    05.06.2024       linie ignorata dupa disclaimer                               9,999.00           99,999.00',
        ]);
    }

    public function testDetectsAndParses(): void
    {
        $parser = new PatriaPdfParser();
        $page = $this->page();
        self::assertSame(100, $parser->score($page->texts()));

        $dispatcher = new PdfStatementDispatcher([$parser]);
        [$s] = $dispatcher->parse([$page]);

        self::assertSame('patria', $s->bankKey);
        self::assertSame('Patria Bank', $s->bankLabel);
        self::assertSame('RO49AAAA1B31007593840000', $s->iban);
        self::assertSame('RON', $s->currency);
        self::assertSame('EXEMPLU SRL', $s->accountHolder);
        self::assertSame('12345678', $s->fiscalCode);
        self::assertSame('11500.00', $s->openingBalance);
        self::assertSame('13250.00', $s->closingBalance);
        self::assertSame([], $s->warnings);
        self::assertCount(2, $s->transactions);

        [$a, $b] = $s->transactions;
        self::assertSame('2024-06-03', $a->date->format('Y-m-d'));
        self::assertSame('1250.00', $a->debit);
        self::assertSame('0.00', $a->credit);
        self::assertSame('123456789', $a->reference);
        self::assertSame('Plata factura 123 FURNIZOR SRL detalii suplimentare', $a->description);
        self::assertSame('10250.00', $a->balance);

        self::assertSame('2024-06-05', $b->date->format('Y-m-d'));
        self::assertSame('3000.00', $b->credit);
        self::assertSame('987654321', $b->reference);
        self::assertSame('Incasare CLIENT SRL', $b->description);
        self::assertSame('13250.00', $b->balance);
    }

    public function testScoreIsLowForOtherBanks(): void
    {
        $parser = new PatriaPdfParser();
        self::assertSame(0, $parser->score(['ING', 'Bank', 'Extras', 'de', 'cont']));
        self::assertSame(50, $parser->score(['Patria', 'Bank', 'S.A.']));
        self::assertSame(100, $parser->score(['www.patriabank.ro']));
    }
}
