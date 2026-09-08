<?php

namespace App\Tests\Unit\PdfBank;

use App\Service\Borderou\Pdf\Bank\IntesaPdfParser;
use App\Service\Borderou\Pdf\PdfPage;
use App\Service\Borderou\Pdf\PdfStatementDispatcher;
use PHPUnit\Framework\TestCase;

class IntesaPdfParserTest extends TestCase
{
    /** Reconstructed from the layout description; not a real statement. */
    private function page(): PdfPage
    {
        return WordFixture::fromLayout([
            'INTESA SANPAOLO BANK ROMANIA S.A.        SWIFT: WBANRO22XXX',
            'EXEMPLU SRL',
            'Adresa: Str. Exemplu nr. 1',
            'COD IBAN: RO49WBAN0000000000000001       Moneda: RON',
            '',
            'Data        Data        Operație                       Curs    Ref.Client   Tip        Rulaj      Sold',
            'Operației   Valutei                                    BNR                  Operație',
            '01.06.2024  01.06.2024                                                      Sold initial          11,500.00',
            '',
            '                        FURNIZOR SRL',
            '03.06.2024  03.06.2024  Plata factura 123              1.0000  REF123456    D          1,250.00   10,250.00',
            '05.06.2024  05.06.2024  Incasare CLIENT SRL                    OP98765      C          3,000.00   13,250.00',
            '30.06.2024  30.06.2024                                                      Sold final            13,250.00',
            '            RULAJ DEBITOR      RULAJ CREDITOR      RULAJ NET',
            '01-06-2024  30-06-2024   1,250.00   3,000.00      1,750.00',
        ]);
    }

    public function testDetectsAndParses(): void
    {
        $parser = new IntesaPdfParser();
        $page = $this->page();
        self::assertSame(100, $parser->score($page->texts()));

        $dispatcher = new PdfStatementDispatcher([$parser]);
        [$s] = $dispatcher->parse([$page]);

        self::assertSame('intesa', $s->bankKey);
        self::assertSame('Intesa Sanpaolo Bank', $s->bankLabel);
        self::assertSame('RO49WBAN0000000000000001', $s->iban);
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
        self::assertSame("FURNIZOR SRL\nPlata factura 123", $a->description);
        self::assertSame('10250.00', $a->balance);

        self::assertSame('2024-06-05', $b->date->format('Y-m-d'));
        self::assertSame('3000.00', $b->credit);
        self::assertSame('OP98765', $b->reference);
        self::assertSame('Incasare CLIENT SRL', $b->description);
        self::assertSame('13250.00', $b->balance);
    }

    public function testScoreIsLowForOtherBanksAndZeroForV2Layout(): void
    {
        $parser = new IntesaPdfParser();
        self::assertSame(0, $parser->score(['ING', 'Bank', 'Extras', 'de', 'cont']));
        self::assertSame(50, $parser->score(['Intesa', 'Sanpaolo', 'Bank']));
        self::assertSame(0, $parser->score(['INTESA', 'SANPAOLO', 'BANK', 'RULAJ', 'SOLD', 'OPERATIE']));
    }
}
