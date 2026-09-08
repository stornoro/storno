<?php

namespace App\Tests\Unit\PdfBank;

use App\Service\Borderou\Pdf\Bank\CecPdfParser;
use App\Service\Borderou\Pdf\Bank\CecV2PdfParser;
use App\Service\Borderou\Pdf\Bank\CecV3PdfParser;
use App\Service\Borderou\Pdf\PdfPage;
use App\Service\Borderou\Pdf\PdfStatement;
use App\Service\Borderou\Pdf\PdfStatementDispatcher;
use PHPUnit\Framework\TestCase;

class CecV2PdfParserTest extends TestCase
{
    /**
     * @param array<int, string> $cells character offset => text (5pt per character)
     */
    private static function row(array $cells): string
    {
        $s = '';
        foreach ($cells as $offset => $text) {
            $s = str_pad($s, $offset) . $text;
        }

        return $s;
    }

    /**
     * Page top (rows 0-3, Top > 700) is outside the upper-left header block; the holder block starts at row 4.
     *
     * @return string[]
     */
    private function preamble(): array
    {
        return [
            self::row([0 => 'CEC Bank S.A.', 40 => 'Mobile Banking', 70 => 'www.cec.ro']),
            self::row([0 => 'Extras de cont']),
            '',
            '',
            self::row([0 => 'DEMO EXEMPLU SRL']),
            self::row([0 => 'RO04CECEB000000000000001']),
            self::row([0 => 'RON']),
            self::row([0 => 'CONTUL TĂU CURENT']),
            self::row([0 => 'ACTIVITATE']),
            self::row([0 => 'Disponibil la data de', 30 => 'Disponibil la data de', 60 => 'Total intrari', 80 => 'Total iesiri']),
            self::row([0 => '01.04.2024', 30 => '30.04.2024']),
            self::row([0 => '(sold initial)', 30 => '(sold final)']),
            self::row([0 => '10,000.00 RON 3,400.50 RON 1,250.00 RON 12,150.50 RON']),
            self::row([0 => 'Detalii tranzactii']),
        ];
    }

    /** Reconstructed from the layout description (three-line table header); not a real statement. */
    private function pageThreeLineHeader(): PdfPage
    {
        return WordFixture::fromLayout(array_merge($this->preamble(), [
            self::row([0 => 'Data', 14 => 'Data', 52 => 'Ref tranz/Nr', 70 => 'Rulaj', 84 => 'Rulaj', 98 => 'Sold']),
            self::row([14 => 'decontării', 52 => 'Detalii', 70 => 'debitor', 84 => 'creditor', 98 => 'după']),
            self::row([0 => 'tranzacției', 52 => 'doc', 98 => 'tranzacție']),
            self::row([0 => '03.04.2024', 14 => '03.04.2024']),
            self::row([14 => 'Plata factura FF-2024-0101', 52 => 'OP17', 70 => '1,250.00', 98 => '8,750.00']),
            self::row([14 => 'Beneficiar: FURNIZOR DEMO SRL']),
            self::row([0 => '05.04.2024', 14 => '05.04.2024']),
            self::row([14 => 'Incasare factura FF-2024-0099', 52 => 'INC42', 84 => '3,400.50', 97 => '12,150.50']),
        ]), 1, 5.0, 12.0, 100.0);
    }

    /** Single-line header variant, with the header repeated in the middle of the table (page break). */
    private function pageSingleLineHeader(): PdfPage
    {
        $header = self::row([0 => 'Data tranzacției', 20 => 'Data decontării', 52 => 'Ref tranz/Nr doc', 70 => 'Rulaj debitor', 84 => 'Rulaj creditor', 100 => 'Sold după tranzacție']);

        return WordFixture::fromLayout(array_merge($this->preamble(), [
            $header,
            self::row([0 => '03.04.2024', 20 => '03.04.2024']),
            self::row([20 => 'Plata factura FF-2024-0101', 52 => 'OP17', 76 => '1,250.00', 112 => '8,750.00']),
            self::row([20 => 'Beneficiar: FURNIZOR DEMO SRL']),
            $header,
            self::row([0 => '05.04.2024', 20 => '05.04.2024']),
            self::row([20 => 'Incasare factura FF-2024-0099', 52 => 'INC42', 90 => '3,400.50', 112 => '12,150.50']),
        ]), 1, 5.0, 12.0, 100.0);
    }

    public function testDetectsAndParsesThreeLineHeader(): void
    {
        $parser = new CecV2PdfParser();
        $page = $this->pageThreeLineHeader();
        self::assertSame(100, $parser->score($page->texts()));
        self::assertSame(70, (new CecV3PdfParser())->score($page->texts()));
        self::assertSame(50, (new CecPdfParser())->score($page->texts()));

        $dispatcher = new PdfStatementDispatcher([new CecPdfParser(), $parser, new CecV3PdfParser()]);
        [$s] = $dispatcher->parse([$page]);
        $this->assertStatement($s);
    }

    public function testParsesSingleLineHeaderAndSkipsRepeatedHeader(): void
    {
        $parser = new CecV2PdfParser();
        $page = $this->pageSingleLineHeader();
        self::assertSame(100, $parser->score($page->texts()));
        [$s] = $parser->parse([$page]);
        $this->assertStatement($s);
    }

    private function assertStatement(PdfStatement $s): void
    {
        self::assertSame('cec', $s->bankKey);
        self::assertSame('CEC Bank', $s->bankLabel);
        self::assertSame('RO04CECEB000000000000001', $s->iban);
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
        self::assertSame('OP17', $a->reference);
        self::assertSame('Plata factura FF-2024-0101 Beneficiar: FURNIZOR DEMO SRL', $a->description);
        self::assertSame('FURNIZOR DEMO SRL', $a->counterpartyName);
        self::assertSame('8750.00', $a->balance);

        self::assertSame('2024-04-05', $b->date->format('Y-m-d'));
        self::assertSame('3400.50', $b->credit);
        self::assertSame('0.00', $b->debit);
        self::assertSame('INC42', $b->reference);
        self::assertSame('Incasare factura FF-2024-0099', $b->description);
        self::assertSame('12150.50', $b->balance);
    }

    public function testScoreIsLowForOtherBanks(): void
    {
        $parser = new CecV2PdfParser();
        self::assertSame(0, $parser->score(['ING', 'Bank', 'Extras', 'de', 'cont']));
        self::assertSame(50, $parser->score(['CEC', 'Bank']));
        self::assertSame(50, $parser->score(['CEC', 'Bank', 'www.ceconline.ro', 'www.cec.ro']));
        self::assertSame(70, $parser->score(['CEC', 'Bank', 'www.cec.ro', 'Titular', 'cont']));
        // "DESCOPERIT DE CONT" belongs to the V3 layout.
        self::assertSame(70, $parser->score(['CEC', 'Bank', 'www.cec.ro', 'Mobile', 'Banking', 'Extras', 'de', 'cont', 'CONTUL', 'TĂU', 'CURENT', 'ACTIVITATE', 'DESCOPERIT', 'DE', 'CONT']));
    }
}
