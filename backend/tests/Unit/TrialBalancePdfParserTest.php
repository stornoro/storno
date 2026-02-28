<?php

namespace App\Tests\Unit;

use App\Service\Balance\TrialBalancePdfParser;
use App\Service\Balance\TrialBalanceParsedResult;
use PHPUnit\Framework\TestCase;

/**
 * Tests for TrialBalancePdfParser regex logic.
 *
 * Since we can't easily mock smalot/pdfparser's internal PDF parsing,
 * we test the detection methods via reflection using raw text that
 * simulates actual PDF text extraction output.
 */
class TrialBalancePdfParserTest extends TestCase
{
    private TrialBalancePdfParser $parser;

    protected function setUp(): void
    {
        $this->parser = new TrialBalancePdfParser();
    }

    // ── Period detection ────────────────────────────────────────────────

    public function testDetectPeriodConcatenatedDates(): void
    {
        $text = "Cont\nDenumirea contului\n01.01.202331.12.2023\nBalanta de verificare";

        $result = $this->callDetectPeriod($text);
        $this->assertSame(2023, $result->year);
        $this->assertSame(12, $result->month);
    }

    public function testDetectPeriodDoubleDash(): void
    {
        $text = "Balanta de verificare\n01.01.2023 -- 31.12.2023";

        $result = $this->callDetectPeriod($text);
        $this->assertSame(2023, $result->year);
        $this->assertSame(12, $result->month);
    }

    public function testDetectPeriodSingleDash(): void
    {
        $text = "Balanta de verificare\n01.01.2025 - 31.06.2025";

        $result = $this->callDetectPeriod($text);
        $this->assertSame(2025, $result->year);
        $this->assertSame(6, $result->month);
    }

    public function testDetectPeriodEnDash(): void
    {
        $text = "Balanta de verificare\n01.01.2024 \u{2013} 30.09.2024";

        $result = $this->callDetectPeriod($text);
        $this->assertSame(2024, $result->year);
        $this->assertSame(9, $result->month);
    }

    public function testDetectPeriodWithPerioada(): void
    {
        $text = "Perioada: 01.01.2025 - 31.03.2025";

        $result = $this->callDetectPeriod($text);
        $this->assertSame(2025, $result->year);
        $this->assertSame(3, $result->month);
    }

    public function testDetectPeriodWithPerioadaDoubleDash(): void
    {
        $text = "Perioada: 01.01.2024 -- 31.07.2024";

        $result = $this->callDetectPeriod($text);
        $this->assertSame(2024, $result->year);
        $this->assertSame(7, $result->month);
    }

    public function testDetectPeriodWithPerioadaSlashFormat(): void
    {
        $text = "Perioada: 01/01/2025 - 28/02/2025";

        $result = $this->callDetectPeriod($text);
        $this->assertSame(2025, $result->year);
        $this->assertSame(2, $result->month);
    }

    public function testDetectPeriodWithLunaMonthName(): void
    {
        $text = "Luna: Decembrie 2024";

        $result = $this->callDetectPeriod($text);
        $this->assertSame(2024, $result->year);
        $this->assertSame(12, $result->month);
    }

    public function testDetectPeriodWithLunaNumeric(): void
    {
        $text = "Luna: 06/2025";

        $result = $this->callDetectPeriod($text);
        $this->assertSame(2025, $result->year);
        $this->assertSame(6, $result->month);
    }

    public function testDetectPeriodReturnsNullForNoMatch(): void
    {
        $text = "This is just some random text without any dates.";

        $result = $this->callDetectPeriod($text);
        $this->assertNull($result->year);
        $this->assertNull($result->month);
    }

    // ── CUI detection ───────────────────────────────────────────────────

    public function testDetectCuiWithCfPrefix(): void
    {
        $text = "TIME SAVER SERVICES S.R.L.   c.f. RO41928329   r.c. J40/15906/2019";

        $result = $this->callDetectCompanyCui($text);
        $this->assertSame('41928329', $result->companyCui);
    }

    public function testDetectCuiWithCfPrefixNoDots(): void
    {
        $text = "ACME SRL cf RO12345678";

        $result = $this->callDetectCompanyCui($text);
        $this->assertSame('12345678', $result->companyCui);
    }

    public function testDetectCuiWithCUI(): void
    {
        $text = "CUI: 87654321";

        $result = $this->callDetectCompanyCui($text);
        $this->assertSame('87654321', $result->companyCui);
    }

    public function testDetectCuiWithCUIDotted(): void
    {
        $text = "C.U.I.: RO12345678";

        $result = $this->callDetectCompanyCui($text);
        $this->assertSame('12345678', $result->companyCui);
    }

    public function testDetectCuiWithCIF(): void
    {
        $text = "CIF: 11223344";

        $result = $this->callDetectCompanyCui($text);
        $this->assertSame('11223344', $result->companyCui);
    }

    public function testDetectCuiWithCIFDotted(): void
    {
        $text = "C.I.F.: RO99887766";

        $result = $this->callDetectCompanyCui($text);
        $this->assertSame('99887766', $result->companyCui);
    }

    public function testDetectCuiWithCodFiscal(): void
    {
        $text = "Cod fiscal: RO55667788";

        $result = $this->callDetectCompanyCui($text);
        $this->assertSame('55667788', $result->companyCui);
    }

    public function testDetectCuiWithCodUnic(): void
    {
        $text = "Cod unic: 44332211";

        $result = $this->callDetectCompanyCui($text);
        $this->assertSame('44332211', $result->companyCui);
    }

    public function testDetectCuiStripsLeadingZeros(): void
    {
        $text = "CUI: 0012345";

        $result = $this->callDetectCompanyCui($text);
        $this->assertSame('12345', $result->companyCui);
    }

    public function testDetectCuiReturnsNullForNoMatch(): void
    {
        $text = "Some random text with no CUI at all.";

        $result = $this->callDetectCompanyCui($text);
        $this->assertNull($result->companyCui);
    }

    // ── Source software detection ────────────────────────────────────────

    public function testDetectSourceSoftwareSagaCaseSensitive(): void
    {
        $text = "Balanta generata de SAGA C v5.2";

        $result = $this->callDetectSourceSoftware($text);
        $this->assertSame('SAGA', $result->sourceSoftware);
    }

    public function testDetectSourceSoftwareCiel(): void
    {
        $text = "Export Ciel Compta";

        $result = $this->callDetectSourceSoftware($text);
        $this->assertSame('Ciel', $result->sourceSoftware);
    }

    public function testDetectSourceSoftwareNone(): void
    {
        $text = "Balanta de verificare\n01.01.2023 -- 31.12.2023";

        $result = $this->callDetectSourceSoftware($text);
        $this->assertNull($result->sourceSoftware);
    }

    // ── Number formatting ───────────────────────────────────────────────

    public function testFormatDecimalSpaceSeparated(): void
    {
        $this->assertSame('1101657.93', $this->callFormatDecimal('1 101 657.93'));
    }

    public function testFormatDecimalRomanianFormat(): void
    {
        $this->assertSame('1234567.89', $this->callFormatDecimal('1.234.567,89'));
    }

    public function testFormatDecimalSimpleRomanian(): void
    {
        $this->assertSame('0.00', $this->callFormatDecimal('0,00'));
    }

    public function testFormatDecimalStandardFormat(): void
    {
        $this->assertSame('1234.56', $this->callFormatDecimal('1234.56'));
    }

    public function testFormatDecimalPlainInteger(): void
    {
        $this->assertSame('0.00', $this->callFormatDecimal('0'));
    }

    public function testFormatDecimalLargeSpaceSeparated(): void
    {
        $this->assertSame('2052519.58', $this->callFormatDecimal('2 052 519.58'));
    }

    public function testFormatDecimalLargeRomanian(): void
    {
        $this->assertSame('3154177.51', $this->callFormatDecimal('3.154.177,51'));
    }

    // ── Account line parsing ────────────────────────────────────────────

    public function testParseAccountLineWithSpaceSeparatedNumbers(): void
    {
        $line = "121  Constructii  1 101 657.93  0.00  0.00  0.00  0.00  0.00  1 101 657.93  0.00  1 101 657.93  0.00";

        $row = $this->callParseAccountLine($line);
        $this->assertNotNull($row);
        $this->assertSame('121', $row['accountCode']);
        $this->assertSame('1101657.93', $row['initialDebit']);
        $this->assertSame('0.00', $row['initialCredit']);
        $this->assertSame('1101657.93', $row['finalDebit']);
        $this->assertSame('0.00', $row['finalCredit']);
    }

    public function testParseAccountLineWithNoSpaceBetweenCodeAndName(): void
    {
        $line = "1012CAPITAL SUBSCRIS VARSAT            0.00          200.00            0.00            0.00            0.00          200.00            0.00          200.00          200.00            0.00";

        $row = $this->callParseAccountLine($line);
        $this->assertNotNull($row);
        $this->assertSame('1012', $row['accountCode']);
        $this->assertSame('0.00', $row['initialDebit']);
        $this->assertSame('200.00', $row['initialCredit']);
        $this->assertSame('200.00', $row['finalDebit']);
        $this->assertSame('0.00', $row['finalCredit']);
    }

    public function testParseAccountLineWithRomanianNumbers(): void
    {
        $line = "411  Clienti  50.000,00  0,00  30.000,00  0,00  25.000,00  10.000,00  55.000,00  10.000,00  45.000,00  0,00";

        $row = $this->callParseAccountLine($line);
        $this->assertNotNull($row);
        $this->assertSame('411', $row['accountCode']);
        $this->assertSame('50000.00', $row['initialDebit']);
        $this->assertSame('0.00', $row['initialCredit']);
        $this->assertSame('45000.00', $row['finalDebit']);
        $this->assertSame('0.00', $row['finalCredit']);
    }

    public function testParseAccountLineReturnsNullForTooFewNumbers(): void
    {
        $line = "121  Constructii  1 101 657.93  0.00";

        $row = $this->callParseAccountLine($line);
        $this->assertNull($row);
    }

    // ── Dash-as-zero feature ───────────────────────────────────────────

    public function testParseAccountLineWithDashesAsZeros(): void
    {
        // Common format: dashes represent zero in columns
        $line = "1012  CAPITAL SUBSCRIS VARSAT  -  200,00  -  -  -  200,00  -  200,00  200,00  -";

        $row = $this->callParseAccountLine($line);
        $this->assertNotNull($row);
        $this->assertSame('1012', $row['accountCode']);
        $this->assertSame('0.00', $row['initialDebit']);
        $this->assertSame('200.00', $row['initialCredit']);
        $this->assertSame('0.00', $row['previousDebit']);
        $this->assertSame('0.00', $row['previousCredit']);
        $this->assertSame('200.00', $row['finalDebit']);
        $this->assertSame('0.00', $row['finalCredit']);
    }

    public function testParseAccountLineWithEnDashAsZero(): void
    {
        // En-dash (–) used as zero
        $line = "401  Furnizori  \u{2013}  1.639,81  \u{2013}  \u{2013}  \u{2013}  1.639,81  \u{2013}  1.639,81  1.639,81  \u{2013}";

        $row = $this->callParseAccountLine($line);
        $this->assertNotNull($row);
        $this->assertSame('401', $row['accountCode']);
        $this->assertSame('0.00', $row['initialDebit']);
        $this->assertSame('1639.81', $row['initialCredit']);
        $this->assertSame('1639.81', $row['finalDebit']);
        $this->assertSame('0.00', $row['finalCredit']);
    }

    public function testParseAccountLineWithMixedDashesAndNumbers(): void
    {
        // Mix of dashes and actual numbers
        $line = "5121  Conturi banca  85.268,11  -  1.342.609,11  504.164,92  1.427.877,22  504.164,92  923.712,30  -  -  85.268,11";

        $row = $this->callParseAccountLine($line);
        $this->assertNotNull($row);
        $this->assertSame('5121', $row['accountCode']);
        $this->assertSame('85268.11', $row['initialDebit']);
        $this->assertSame('0.00', $row['initialCredit']);
        $this->assertSame('0.00', $row['finalDebit']);
        $this->assertSame('85268.11', $row['finalCredit']);
    }

    // ── Take-last-10 strategy ──────────────────────────────────────────

    public function testParseAccountLineWithNumberInName(): void
    {
        // Account name contains a number "NR 5" — should still work because we take last 10
        $line = "2131  ECHIPAMENTE NR 5  0,00  0,00  0,00  0,00  1.500,00  0,00  1.500,00  0,00  1.500,00  0,00";

        $row = $this->callParseAccountLine($line);
        $this->assertNotNull($row);
        $this->assertSame('2131', $row['accountCode']);
        // The "5" in the name is an extra number, but we take the last 10
        $this->assertSame('0.00', $row['initialDebit']);
        $this->assertSame('0.00', $row['initialCredit']);
        $this->assertSame('1500.00', $row['finalDebit']);
        $this->assertSame('0.00', $row['finalCredit']);
    }

    // ── Multi-line account entries ──────────────────────────────────────

    public function testParseRowsJoinsMultiLineAccounts(): void
    {
        $text = <<<TEXT
1171REZULTATUL REPORTAT - PROFITUL
NEREP./ PIREDERE NEACOP.
             0.00    1 101 657.93            0.00    2 052 519.58            0.00    3 154 177.51            0.00    3 154 177.51    1 101 657.93            0.00
TEXT;

        $result = $this->parser->parseFromText($text);
        $this->assertCount(1, $result->rows);
        $this->assertSame('1171', $result->rows[0]['accountCode']);
        $this->assertSame('0.00', $result->rows[0]['initialDebit']);
        $this->assertSame('1101657.93', $result->rows[0]['initialCredit']);
        $this->assertSame('1101657.93', $result->rows[0]['finalDebit']);
        $this->assertSame('0.00', $result->rows[0]['finalCredit']);
    }

    public function testParseRowsSkipsTotalLines(): void
    {
        $text = <<<TEXT
1012CAPITAL SUBSCRIS VARSAT            0.00          200.00            0.00            0.00            0.00          200.00            0.00          200.00          200.00            0.00
Total sume clasa
1            0.00    3 154 417.51    2 409 897.00    4 835 843.64    2 409 897.00    7 990 261.15            0.00    5 580 364.15            0.00    3 154 417.51
TEXT;

        $result = $this->parser->parseFromText($text);
        $this->assertCount(1, $result->rows);
        $this->assertSame('1012', $result->rows[0]['accountCode']);
    }

    public function testParseRowsHandlesRepeatedPageHeaders(): void
    {
        $text = <<<TEXT
1012CAPITAL SUBSCRIS VARSAT            0.00          200.00            0.00            0.00            0.00          200.00            0.00          200.00          200.00            0.00
Cont
Denumirea contului
DebitoareCreditoareDebitoareCreditoareDebitoareCreditoareDebitoareCreditoare
01.01.202331.12.2023
DebitoareCreditoare
Solduri initiale anSume precedenteRulaje perioadaSume totaleSolduri finale
Balanta de verificare
TIME SAVER SERVICES S.R.L.   c.f. RO41928329
--
1061REZERVE LEGALE            0.00           40.00            0.00            0.00            0.00           40.00            0.00           40.00           40.00            0.00
TEXT;

        $result = $this->parser->parseFromText($text);
        $this->assertCount(2, $result->rows);
        $this->assertSame('1012', $result->rows[0]['accountCode']);
        $this->assertSame('1061', $result->rows[1]['accountCode']);
    }

    // ── Dash-as-zero full parse ─────────────────────────────────────────

    public function testFullParseDashFormat(): void
    {
        $text = <<<TEXT
Societatea: TEST SRL
CUI: 12345678
Perioada: 01.01.2025 - 31.01.2025
Cont  Denumire  Sold initial D  C  Rulaj prec D  C  Rulaj curent D  C  Total D  C  Sold final D  C
---
411  Clienti  50.000,00  -  30.000,00  -  25.000,00  10.000,00  55.000,00  10.000,00  45.000,00  -
401  Furnizori  -  20.000,00  -  15.000,00  10.000,00  -  10.000,00  35.000,00  -  25.000,00
5121  Banca  100.000,00  -  -  -  20.000,00  15.000,00  20.000,00  15.000,00  105.000,00  -
7011  Venituri  -  -  -  80.000,00  -  30.000,00  -  110.000,00  -  110.000,00
641  Salarii  -  -  40.000,00  -  15.000,00  -  55.000,00  -  55.000,00  -
TOTAL  150.000,00  20.000,00  70.000,00  95.000,00  70.000,00  55.000,00  140.000,00  170.000,00  205.000,00  135.000,00
TEXT;

        $result = $this->parser->parseFromText($text);

        $this->assertSame(2025, $result->year);
        $this->assertSame(1, $result->month);
        $this->assertSame('12345678', $result->companyCui);

        $this->assertCount(5, $result->rows);

        $rowsByCode = [];
        foreach ($result->rows as $row) {
            $rowsByCode[$row['accountCode']] = $row;
        }

        // 411 with dashes as zeros
        $this->assertArrayHasKey('411', $rowsByCode);
        $this->assertSame('50000.00', $rowsByCode['411']['initialDebit']);
        $this->assertSame('0.00', $rowsByCode['411']['initialCredit']);
        $this->assertSame('45000.00', $rowsByCode['411']['finalDebit']);
        $this->assertSame('0.00', $rowsByCode['411']['finalCredit']);

        // 401 with dashes
        $this->assertArrayHasKey('401', $rowsByCode);
        $this->assertSame('0.00', $rowsByCode['401']['initialDebit']);
        $this->assertSame('20000.00', $rowsByCode['401']['initialCredit']);
        $this->assertSame('0.00', $rowsByCode['401']['finalDebit']);
        $this->assertSame('25000.00', $rowsByCode['401']['finalCredit']);

        // 5121 with many dashes
        $this->assertArrayHasKey('5121', $rowsByCode);
        $this->assertSame('100000.00', $rowsByCode['5121']['initialDebit']);
        $this->assertSame('0.00', $rowsByCode['5121']['initialCredit']);
        $this->assertSame('105000.00', $rowsByCode['5121']['finalDebit']);
        $this->assertSame('0.00', $rowsByCode['5121']['finalCredit']);

        // 7011 - revenue with all-dash initial
        $this->assertArrayHasKey('7011', $rowsByCode);
        $this->assertSame('0.00', $rowsByCode['7011']['initialDebit']);
        $this->assertSame('0.00', $rowsByCode['7011']['initialCredit']);
        $this->assertSame('0.00', $rowsByCode['7011']['finalDebit']);
        $this->assertSame('110000.00', $rowsByCode['7011']['finalCredit']);
    }

    // ── Skippable lines ─────────────────────────────────────────────────

    /**
     * @dataProvider skippableLineProvider
     */
    public function testIsSkippableLine(string $line, bool $expected): void
    {
        $this->assertSame($expected, $this->callIsSkippableLine($line));
    }

    public static function skippableLineProvider(): array
    {
        return [
            'TOTAL line' => ['TOTAL  1 234 567.89  0.00', true],
            'Total clasa' => ['Total clasa 1  500 000.00', true],
            'Total sume clasa' => ['Total sume clasa', true],
            'Clasa header' => ['Clasa 1 - CAPITAL', true],
            'Cont standalone' => ['Cont', true],
            'Cont with space' => ['Cont  Denumire  Sold initial D', true],
            'Simbol header' => ['Simbol  Denumire  Debit  Credit', true],
            'Pagina' => ['Pagina 1 din 5', true],
            'Pag' => ['Pag 2', true],
            'Balanta header' => ['Balanta de verificare', true],
            'Societatea' => ['Societatea ACME SRL', true],
            'Perioada' => ['Perioada: 01.01.2023 - 31.12.2023', true],
            'Luna' => ['Luna: Decembrie 2024', true],
            'Solduri initiale' => ['Solduri initiale an', true],
            'Sold initial' => ['Sold initial  Debit  Credit', true],
            'Rulaj' => ['Rulaj precedent', true],
            'Rulaje' => ['Rulaje perioada', true],
            'Sume precedente' => ['Sume precedente', true],
            'Sume totale' => ['Sume totale', true],
            'Denumirea' => ['Denumirea contului', true],
            'Debitoare' => ['Debitoare  Creditoare', true],
            'DebitoareCreditoare concatenated' => ['DebitoareCreditoareDebitoareCreditoare', true],
            'Creditoare' => ['Creditoare', true],
            'Two dashes' => ['--', true],
            'Three dashes' => ['-------------------', true],
            'Equals' => ['===================', true],
            'c.f. line' => ['c.f. RO41928329  r.c. J40/15906/2019', true],
            'r.c. line' => ['r.c. J40/15906/2019', true],
            'Capital social' => ['Capital social 200', true],
            'Den cont' => ['Den cont  Sold initial  Rulaj', true],
            'Nr. crt header' => ['Nr. crt  Simbol cont  Debit', true],
            'Account line (not skippable)' => ['411  Clienti  50.000,00  0,00', false],
            'Account no-space (not skippable)' => ['1012CAPITAL SUBSCRIS VARSAT  0.00', false],
        ];
    }

    // ── Full text simulation (TIME SAVER SERVICES format) ───────────────

    public function testFullParseTimeSaverFormat(): void
    {
        $text = <<<'TEXT'
Cont
Denumirea contului
DebitoareCreditoareDebitoareCreditoareDebitoareCreditoareDebitoareCreditoare
01.01.202331.12.2023
DebitoareCreditoare
Solduri initiale anSume precedenteRulaje perioadaSume totaleSolduri finale
Balanta de verificare
TIME SAVER SERVICES S.R.L.   c.f. RO41928329   r.c. J40/15906/2019  Capital social 200
BUCURESTI sect. 6 str. SOS VIRTUTII nr. 19D et. 4 cod postal BIR B tel. 0756100151
--
1012CAPITAL SUBSCRIS VARSAT            0.00          200.00            0.00            0.00            0.00          200.00            0.00          200.00          200.00            0.00
1061REZERVE LEGALE            0.00           40.00            0.00            0.00            0.00           40.00            0.00           40.00           40.00            0.00
1171REZULTATUL REPORTAT - PROFITUL
NEREP./ PIREDERE NEACOP.
             0.00    1 101 657.93            0.00    2 052 519.58            0.00    3 154 177.51            0.00    3 154 177.51    1 101 657.93            0.00
121PROFIT SI PIERDERE            0.00    2 052 519.58    2 409 897.00    2 783 324.06    2 409 897.00    4 835 843.64            0.00    2 425 946.64    2 052 519.58            0.00
Total sume clasa
1            0.00    3 154 417.51    2 409 897.00    4 835 843.64    2 409 897.00    7 990 261.15            0.00    5 580 364.15            0.00    3 154 417.51
401FURNIZORI            0.00        1 639.81      325 924.91      328 564.72      325 924.91      330 204.53            0.00        4 279.62        1 639.81            0.00
5121CONTURI LA BANCA IN LEI       85 268.11            0.00    1 342 609.11      504 164.92    1 427 877.22      504 164.92      923 712.30            0.00            0.00       85 268.11
641CHELT. CU SALARIILE PERSONALULUI            0.00            0.00       36 900.00       36 900.00       36 900.00       36 900.00            0.00            0.00            0.00            0.00
7041VEN. DIN SERVICII PRESTATE            0.00            0.00            0.00    2 409 897.00            0.00    2 409 897.00            0.00    2 409 897.00            0.00            0.00
TOTAL    2 261 957.60    2 261 957.60   10 803 637.90   10 803 637.90   13 065 595.50   13 065 595.50    3 024 975.60    3 024 975.60    2 261 957.60    2 261 957.60
TEXT;

        $result = $this->parser->parseFromText($text);

        $this->assertSame(2023, $result->year);
        $this->assertSame(12, $result->month);
        $this->assertSame('41928329', $result->companyCui);

        $this->assertGreaterThanOrEqual(7, count($result->rows), 'Should parse at least 7 account rows');

        $rowsByCode = [];
        foreach ($result->rows as $row) {
            $rowsByCode[$row['accountCode']] = $row;
        }

        // Account 1012
        $this->assertArrayHasKey('1012', $rowsByCode);
        $this->assertSame('0.00', $rowsByCode['1012']['initialDebit']);
        $this->assertSame('200.00', $rowsByCode['1012']['initialCredit']);
        $this->assertSame('200.00', $rowsByCode['1012']['finalDebit']);
        $this->assertSame('0.00', $rowsByCode['1012']['finalCredit']);

        // Account 1171 - Multi-line account name
        $this->assertArrayHasKey('1171', $rowsByCode);
        $this->assertSame('0.00', $rowsByCode['1171']['initialDebit']);
        $this->assertSame('1101657.93', $rowsByCode['1171']['initialCredit']);
        $this->assertSame('1101657.93', $rowsByCode['1171']['finalDebit']);
        $this->assertSame('0.00', $rowsByCode['1171']['finalCredit']);

        // Account 401 - Furnizori
        $this->assertArrayHasKey('401', $rowsByCode);
        $this->assertSame('0.00', $rowsByCode['401']['initialDebit']);
        $this->assertSame('1639.81', $rowsByCode['401']['initialCredit']);
        $this->assertSame('1639.81', $rowsByCode['401']['finalDebit']);
        $this->assertSame('0.00', $rowsByCode['401']['finalCredit']);

        // Account 5121 - Banca
        $this->assertArrayHasKey('5121', $rowsByCode);
        $this->assertSame('85268.11', $rowsByCode['5121']['initialDebit']);
        $this->assertSame('923712.30', $rowsByCode['5121']['totalDebit']);

        // Account 641 - Salarii
        $this->assertArrayHasKey('641', $rowsByCode);
        $this->assertSame('36900.00', $rowsByCode['641']['currentDebit']);

        // Account 7041 - Venituri
        $this->assertArrayHasKey('7041', $rowsByCode);
        $this->assertSame('0.00', $rowsByCode['7041']['finalDebit']);
        $this->assertSame('0.00', $rowsByCode['7041']['finalCredit']);
        $this->assertSame('2409897.00', $rowsByCode['7041']['totalCredit']);
    }

    // ── Full text simulation (Romanian format — SAGA style) ─────────────

    public function testFullParseRomanianFormat(): void
    {
        $text = <<<TEXT
Societatea: EXEMPLU SRL
C.U.I.: RO12345678
Balanta de verificare
Perioada: 01.01.2025 - 31.01.2025
SAGA C v5.2
Simbol  Denumire cont  Sold initial D  Sold initial C  Rulaj prec D  Rulaj prec C  Rulaj curent D  Rulaj curent C  Total D  Total C  Sold final D  Sold final C
-------------------------------------------
411  Clienti  50.000,00  0,00  30.000,00  0,00  25.000,00  10.000,00  55.000,00  10.000,00  45.000,00  0,00
401  Furnizori  0,00  20.000,00  0,00  15.000,00  10.000,00  0,00  10.000,00  35.000,00  0,00  25.000,00
5121  Conturi banca  100.000,00  0,00  50.000,00  30.000,00  20.000,00  15.000,00  70.000,00  45.000,00  125.000,00  0,00
7011  Venituri vanzari  0,00  0,00  0,00  80.000,00  0,00  30.000,00  0,00  110.000,00  0,00  110.000,00
641  Salarii  0,00  0,00  40.000,00  0,00  15.000,00  0,00  55.000,00  0,00  55.000,00  0,00
TOTAL  150.000,00  20.000,00  120.000,00  125.000,00  70.000,00  55.000,00  190.000,00  200.000,00  225.000,00  135.000,00
TEXT;

        $result = $this->parser->parseFromText($text);

        $this->assertSame(2025, $result->year);
        $this->assertSame(1, $result->month);
        $this->assertSame('12345678', $result->companyCui);
        $this->assertSame('SAGA', $result->sourceSoftware);

        $this->assertGreaterThanOrEqual(5, count($result->rows));

        $rowsByCode = [];
        foreach ($result->rows as $row) {
            $rowsByCode[$row['accountCode']] = $row;
        }

        $this->assertArrayHasKey('411', $rowsByCode);
        $this->assertSame('50000.00', $rowsByCode['411']['initialDebit']);
        $this->assertSame('45000.00', $rowsByCode['411']['finalDebit']);

        $this->assertArrayHasKey('5121', $rowsByCode);
        $this->assertSame('100000.00', $rowsByCode['5121']['initialDebit']);
        $this->assertSame('125000.00', $rowsByCode['5121']['finalDebit']);

        $this->assertArrayHasKey('7011', $rowsByCode);
        $this->assertSame('110000.00', $rowsByCode['7011']['finalCredit']);
    }

    // ── Helpers to call private methods via reflection ───────────────────

    private function callDetectPeriod(string $text): TrialBalanceParsedResult
    {
        $result = new TrialBalanceParsedResult();
        $method = new \ReflectionMethod($this->parser, 'detectPeriod');
        $method->invoke($this->parser, $text, $result);
        return $result;
    }

    private function callDetectCompanyCui(string $text): TrialBalanceParsedResult
    {
        $result = new TrialBalanceParsedResult();
        $method = new \ReflectionMethod($this->parser, 'detectCompanyCui');
        $method->invoke($this->parser, $text, $result);
        return $result;
    }

    private function callDetectSourceSoftware(string $text): TrialBalanceParsedResult
    {
        $result = new TrialBalanceParsedResult();
        $method = new \ReflectionMethod($this->parser, 'detectSourceSoftware');
        $method->invoke($this->parser, $text, $result);
        return $result;
    }

    private function callFormatDecimal(string $value): string
    {
        $method = new \ReflectionMethod($this->parser, 'formatDecimal');
        return $method->invoke($this->parser, $value);
    }

    private function callParseAccountLine(string $line): ?array
    {
        $method = new \ReflectionMethod($this->parser, 'parseAccountLine');
        return $method->invoke($this->parser, $line);
    }

    private function callIsSkippableLine(string $line): bool
    {
        $method = new \ReflectionMethod($this->parser, 'isSkippableLine');
        return $method->invoke($this->parser, $line);
    }
}
