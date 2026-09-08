<?php

namespace App\Tests\Unit\PdfBank;

use App\Service\Borderou\Pdf\Bank\CecPdfParser;
use App\Service\Borderou\Pdf\Bank\CecV2PdfParser;
use App\Service\Borderou\Pdf\Bank\CecV3PdfParser;
use App\Service\Borderou\Pdf\PdfPage;
use App\Service\Borderou\Pdf\PdfStatementDispatcher;
use PHPUnit\Framework\TestCase;

class CecPdfParserTest extends TestCase
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

    /** Reconstructed from the layout description; not a real statement. */
    private function page(): PdfPage
    {
        return WordFixture::fromLayout([
            self::row([0 => 'CEC Bank S.A.', 50 => 'www.cec.ro']),
            self::row([0 => 'Titular cont: DEMO EXEMPLU SRL', 50 => 'IBAN: RO04CECEB000000000000001']),
            self::row([0 => 'Valuta extras: RON']),
            self::row([0 => 'Sold initial: 10.000,00', 50 => 'Sold final: 12.150,50']),
            self::row([0 => 'Nr.crt.', 9 => 'Data', 21 => 'Detalii', 60 => 'Numar ordin client', 81 => 'Suma']),
            self::row([0 => 'crt.', 81 => '(RON)']),
            self::row([0 => '----']),
            self::row([0 => '1', 9 => '03.04.2024', 21 => 'Plata factura FF-2024-0101', 74 => '-1.250,00']),
            self::row([21 => 'Ordonator: DEMO EXEMPLU SRL', 60 => 'OP 17']),
            self::row([0 => '2', 9 => '05.04.2024', 21 => 'Incasare factura FF-2024-0099', 75 => '3.400,50']),
            self::row([21 => 'Ordonator: CLIENT EXEMPLU SRL', 60 => 'INC 42']),
        ], 1, 5.0, 12.0, 100.0);
    }

    public function testDetectsAndParses(): void
    {
        $parser = new CecPdfParser();
        $page = $this->page();
        self::assertSame(100, $parser->score($page->texts()));
        self::assertSame(70, (new CecV2PdfParser())->score($page->texts()));
        self::assertSame(70, (new CecV3PdfParser())->score($page->texts()));

        $dispatcher = new PdfStatementDispatcher([new CecV2PdfParser(), new CecV3PdfParser(), $parser]);
        [$s] = $dispatcher->parse([$page]);

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
        self::assertSame('OP 17', $a->reference);
        self::assertSame('Plata factura FF-2024-0101 Ordonator: DEMO EXEMPLU SRL', $a->description);
        self::assertSame('DEMO EXEMPLU SRL', $a->counterpartyName);
        self::assertSame('8750.00', $a->balance);

        self::assertSame('2024-04-05', $b->date->format('Y-m-d'));
        self::assertSame('3400.50', $b->credit);
        self::assertSame('0.00', $b->debit);
        self::assertSame('INC 42', $b->reference);
        self::assertSame('Incasare factura FF-2024-0099 Ordonator: CLIENT EXEMPLU SRL', $b->description);
        self::assertSame('12150.50', $b->balance);
    }

    public function testScoreIsLowForOtherBanks(): void
    {
        $parser = new CecPdfParser();
        self::assertSame(0, $parser->score(['ING', 'Bank', 'Extras', 'de', 'cont']));
        self::assertSame(50, $parser->score(['CEC', 'Bank']));
        self::assertSame(50, $parser->score(['www.ceconline.ro']));
        // Mobile Banking layouts (no "Titular cont") never reach 100.
        self::assertSame(50, $parser->score(['CEC', 'Bank', 'www.cec.ro', 'Mobile', 'Banking']));
    }
}
