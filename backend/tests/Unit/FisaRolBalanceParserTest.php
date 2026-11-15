<?php

namespace App\Tests\Unit;

use App\Service\Spv\FisaRolBalanceParser;
use PHPUnit\Framework\TestCase;

class FisaRolBalanceParserTest extends TestCase
{
    private const TEXT = <<<TXT
Administraţia Sector 1 a Finanţelor Publice
                                   FISA SINTETICA TOTALA
Cod de identificare fiscala: 12345678
EXEMPLU S.R.L.
Calcul accesorii pana la data de :16/09/2026
        2   Impozit pe veniturile din salarii
Nr.                                           Scadenta/                   Ctg.
 0                    1                            2              3        4        5            6                 7                   8                      9                10             11                  12                13             14
75     DEC./1040720213/19.01.26               25/01/2026     25/01/2026    D                        1000                    0                   0                        0                          0                        0                 0   SCS
       I/2                                    25/01/2026     19/01/2026                                0                    0                   0                        0     Pl                1000                        0                 0
       Total impozit:                                                                                 2000                1000                     5                        3                   1000                    0           0
        3   Impozit pe profit
       Total impozit:                                                                                 5000                   0                    79                       40                   5000                    0           0
        480    Contributie asiguratorie pentru munca
       Total impozit:                                                                                  300                 300                     2                        1                      0                    0           0
        9902     Venituri ale bugetului de stat incasate in contul unic, in curs de distribuire
       Total impozit:                                                                                    0                   0                     0                        0                      0                    0           0
Total cod fiscal: 12345678                                                                  7300                  1300         86                44                        6000          0         0
TXT;

    public function testReadsTotalsAndPerTaxBreakdown(): void
    {
        $result = (new FisaRolBalanceParser('/nonexistent/pdftotext'))->parseText(self::TEXT);

        self::assertNotNull($result);
        self::assertSame('12345678', $result['cif']);
        self::assertSame('2026-09-16', $result['asOf']);
        self::assertSame(7300.0, $result['obligations']);
        self::assertSame(1300.0, $result['unpaid']);
        self::assertSame(86.0, $result['interest']);
        self::assertSame(44.0, $result['penalties']);
        self::assertSame(6000.0, $result['paid']);

        // Document rows (DEC./…, I/…) are never taken for a tax name; taxes with nothing pending are left out
        self::assertSame(
            ['Impozit pe veniturile din salarii', 'Impozit pe profit', 'Contributie asiguratorie pentru munca'],
            array_column($result['unpaidByTax'], 'tax'),
        );
        self::assertSame(1000.0, $result['unpaidByTax'][0]['unpaid']);
        self::assertSame(0.0, $result['unpaidByTax'][1]['unpaid']);
        self::assertSame(79.0, $result['unpaidByTax'][1]['interest']);
    }

    public function testThousandsSeparatorsAndDecimals(): void
    {
        $text = "Calcul accesorii pana la data de :01/11/2026\nTotal cod fiscal: 12345678   1.423.100   25.250,50   124   61   1.397.850";
        $result = (new FisaRolBalanceParser('/nonexistent/pdftotext'))->parseText($text);

        self::assertSame(1423100.0, $result['obligations']);
        self::assertSame(25250.5, $result['unpaid']);
    }

    public function testOtherDocumentsAreNotParsed(): void
    {
        $parser = new FisaRolBalanceParser('/nonexistent/pdftotext');
        self::assertNull($parser->parseText("SOMATIE\nTotal 5.795"));
        self::assertNull($parser->parsePdf('%PDF-1.4 whatever'), 'without pdftotext nothing is read');
        self::assertNull($parser->parsePdf('not a pdf'));
    }
}
