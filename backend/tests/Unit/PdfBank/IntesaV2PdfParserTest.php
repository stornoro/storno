<?php

namespace App\Tests\Unit\PdfBank;

use App\Service\Borderou\Pdf\Bank\IntesaV2PdfParser;
use App\Service\Borderou\Pdf\PdfPage;
use App\Service\Borderou\Pdf\PdfStatementDispatcher;
use PHPUnit\Framework\TestCase;

class IntesaV2PdfParserTest extends TestCase
{
    /** Reconstructed from the layout description; not a real statement. Table rows are indented (x >= 25). */
    private function page1(): PdfPage
    {
        return WordFixture::fromLayout([
            '      INTESA SANPAOLO BANK ROMANIA S.A.',
            '      Cont: 1234567890      Cod IBAN: RO49WBAN0000000000000001      Moneda: RON',
            '      EXEMPLU SRL (CIF 12345678)',
            '      DATA        DATA        OPERATIE                      CURS     REF.CLIENT   RULAJ           SOLD',
            '      OPERATIEI   VALUTEI                                   BNR',
            '      Sold initial                                                                            11,500.00 C',
            '      03.06.2024  03.06.2024  Plata factura 123             1.0000   REF123456    1,250.00 D   10,250.00 C',
            '                              FURNIZOR SRL',
            '      05.06.2024  05.06.2024  Incasare CLIENT SRL                    OP98765      3,000.00 C   13,250.00 C',
            '      Sold final                                                                              13,250.00 C',
            '      01-06-2024  30-06-2024  RULAJ  1,250.00  3,000.00  1,750.00',
            'Sediul central: Bucuresti',
        ]);
    }

    private function page2(): PdfPage
    {
        return WordFixture::fromLayout([
            '      INTESA SANPAOLO BANK ROMANIA S.A.',
            '      Cont: 9876543210      Cod IBAN: RO49WBAN0000000000000002      Moneda: EUR',
            '      EXEMPLU SRL (CIF 12345678)',
            '      DATA        DATA        OPERATIE                      CURS     REF.CLIENT   RULAJ           SOLD',
            '      OPERATIEI   VALUTEI                                   BNR',
            '      Sold initial                                                                               500.00 C',
            '      10.06.2024  10.06.2024  Comision                                        FEE1             5.00 D      495.00 C',
            '      Sold final                                                                                 495.00 C',
            '      01-06-2024  30-06-2024  RULAJ  5.00  0.00  -5.00',
            'Sediul central: Bucuresti',
        ], 2);
    }

    public function testDetectsAndParsesMultipleAccounts(): void
    {
        $parser = new IntesaV2PdfParser();
        $page1 = $this->page1();
        self::assertSame(100, $parser->score($page1->texts()));

        $dispatcher = new PdfStatementDispatcher([$parser]);
        $statements = $dispatcher->parse([$page1, $this->page2()]);
        self::assertCount(2, $statements);
        [$s, $s2] = $statements;

        self::assertSame('intesa', $s->bankKey);
        self::assertSame('Intesa Sanpaolo Bank', $s->bankLabel);
        self::assertSame('RO49WBAN0000000000000001', $s->iban);
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
        self::assertSame('REF123456', $a->reference);
        self::assertSame("Plata factura 123\nFURNIZOR SRL", $a->description);
        self::assertSame('10250.00', $a->balance);

        self::assertSame('2024-06-05', $b->date->format('Y-m-d'));
        self::assertSame('3000.00', $b->credit);
        self::assertSame('OP98765', $b->reference);
        self::assertSame('Incasare CLIENT SRL', $b->description);
        self::assertSame('13250.00', $b->balance);

        self::assertSame('RO49WBAN0000000000000002', $s2->iban);
        self::assertSame('EUR', $s2->currency);
        self::assertSame('500.00', $s2->openingBalance);
        self::assertSame('495.00', $s2->closingBalance);
        self::assertSame([], $s2->warnings);
        self::assertCount(1, $s2->transactions);
        self::assertSame('5.00', $s2->transactions[0]->debit);
        self::assertSame('FEE1', $s2->transactions[0]->reference);
        self::assertSame('Comision', $s2->transactions[0]->description);
    }

    public function testScoreIsZeroWithoutUpperCaseHeader(): void
    {
        $parser = new IntesaV2PdfParser();
        self::assertSame(0, $parser->score(['ING', 'Bank', 'RULAJ', 'SOLD', 'OPERATIE']));
        self::assertSame(0, $parser->score(['Intesa', 'Sanpaolo', 'Bank', 'Rulaj', 'Sold']));
        self::assertSame(100, $parser->score(['WBANRO22XXX', 'RULAJ', 'SOLD', 'OPERATIE']));
    }
}
