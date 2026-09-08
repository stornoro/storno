<?php

namespace App\Tests\Unit\PdfBank;

use App\Service\Borderou\Pdf\Bank\CecPdfParser;
use App\Service\Borderou\Pdf\Bank\CecV2PdfParser;
use App\Service\Borderou\Pdf\Bank\CecV3PdfParser;
use App\Service\Borderou\Pdf\PdfPage;
use App\Service\Borderou\Pdf\PdfStatementDispatcher;
use PHPUnit\Framework\TestCase;

class CecV3PdfParserTest extends TestCase
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
            self::row([0 => 'CEC Bank S.A.', 40 => 'Mobile Banking', 70 => 'www.cec.ro']),
            self::row([0 => 'DESCOPERIT DE CONT']),
            '',
            '',
            self::row([0 => 'DEMO EXEMPLU SRL']),
            self::row([0 => 'RO04CECEB000000000000001']),
            self::row([0 => 'RON']),
            self::row([0 => 'Disponibil la data de', 30 => 'Disponibil la data de', 60 => 'Total intrari', 80 => 'Total iesiri']),
            self::row([0 => '01.04.2024', 30 => '30.04.2024']),
            self::row([0 => '(sold initial)', 30 => '(sold final)']),
            self::row([0 => '10.000,00 RON 3.400,50 RON 1.250,00 RON 12.150,50 RON']),
            self::row([0 => 'Data tranzacției', 19 => 'Data decontării', 50 => 'Ref tranz/Nr doc', 68 => 'Detalii', 80 => 'Suma']),
            self::row([0 => '--------']),
            self::row([0 => '03.04.2024', 19 => '03.04.2024']),
            self::row([19 => 'Plata factura FF-2024-0101', 52 => 'OP17', 78 => '-1.250,00']),
            self::row([19 => 'Beneficiar: FURNIZOR DEMO SRL']),
            self::row([0 => '05.04.2024', 19 => '05.04.2024']),
            self::row([19 => 'Incasare factura FF-2024-0099', 52 => 'INC42', 79 => '3.400,50']),
            self::row([0 => 'Total intrări', 79 => '3.400,50']),
            self::row([0 => 'Total ieșiri', 79 => '1.250,00']),
            self::row([0 => '31.05.2024', 19 => '31.05.2024']),
            self::row([19 => 'Rand de dupa total care nu trebuie citit', 79 => '9.999,99']),
        ], 1, 5.0, 12.0, 100.0);
    }

    public function testDetectsAndParses(): void
    {
        $parser = new CecV3PdfParser();
        $page = $this->page();
        self::assertSame(100, $parser->score($page->texts()));
        self::assertSame(70, (new CecV2PdfParser())->score($page->texts()));
        self::assertSame(50, (new CecPdfParser())->score($page->texts()));

        $dispatcher = new PdfStatementDispatcher([new CecPdfParser(), new CecV2PdfParser(), $parser]);
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
        self::assertCount(2, $s->transactions, 'parsing must stop at "Total intrari"');

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
        $parser = new CecV3PdfParser();
        self::assertSame(0, $parser->score(['ING', 'Bank', 'Extras', 'de', 'cont']));
        self::assertSame(50, $parser->score(['CEC', 'Bank']));
        self::assertSame(50, $parser->score(['CEC', 'Bank', 'www.ceconline.ro', 'www.cec.ro']));
        // "Extras de cont" / "CONTUL TAU CURENT" belong to the V2 layout, "Titular cont" to the classic one.
        self::assertSame(70, $parser->score(['CEC', 'Bank', 'www.cec.ro', 'Mobile', 'Banking', 'DESCOPERIT', 'DE', 'CONT', 'Extras', 'de', 'cont']));
        self::assertSame(70, $parser->score(['CEC', 'Bank', 'www.cec.ro', 'Mobile', 'Banking', 'DESCOPERIT', 'DE', 'CONT', 'Titular', 'cont']));
    }
}
