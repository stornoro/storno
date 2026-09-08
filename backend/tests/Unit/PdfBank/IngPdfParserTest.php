<?php

namespace App\Tests\Unit\PdfBank;

use App\Service\Borderou\Pdf\Bank\IngPdfParser;
use App\Service\Borderou\Pdf\Bank\IngV2PdfParser;
use App\Service\Borderou\Pdf\PdfPage;
use App\Service\Borderou\Pdf\PdfStatementDispatcher;
use PHPUnit\Framework\TestCase;

class IngPdfParserTest extends TestCase
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
        // top=100 => row 0 is at Top 742 (PDF coords), inside the 75 < Top < 750 table band;
        // the holder box (Top <= 750, Bottom >= 725) is row 0 and the currency box (Top <= 720) is row 2.
        return WordFixture::fromLayout([
            self::row([0 => 'Extras de cont', 62 => 'Titular cont: EXEMPLU COM SRL CUI: 12345678']),
            self::row([0 => 'ING Bank N.V. Amsterdam Sucursala Bucuresti', 46 => 'Bd. Aviator Popisteanu 1A', 80 => 'Numar extras cont 2/2024']),
            self::row([6 => 'Valuta: RON', 30 => 'Cont: RO09INGB0000999900001234']),
            self::row([0 => 'Perioada: 01.02.2024 - 29.02.2024']),
            self::row([0 => 'Rezumat']),
            self::row([0 => 'Sold initial', 47 => '1,000.00']),
            self::row([0 => 'Sold final', 47 => '1,950.50']),
            '',
            self::row([0 => 'Data', 13 => 'Referinta bancii', 32 => 'Descrierea tranzactiei', 70 => 'Debitari', 83 => 'Creditari', 96 => 'Sold']),
            self::row([0 => '01.02.2024', 32 => 'Plata factura F1234', 70 => '250.00', 96 => '750.00']),
            self::row([13 => '1234567890', 32 => 'Beneficiar: FURNIZOR TEST SRL']),
            self::row([32 => 'Referinta bancii: 1234567890']),
            self::row([0 => '05.02.2024', 32 => 'Incasare client', 83 => '1,200.50', 96 => '1,950.50']),
            self::row([32 => 'Referinta interna: ABC-77']),
        ], 1, 5.0, 12.0, 100.0);
    }

    public function testDetectsAndParses(): void
    {
        $parser = new IngPdfParser();
        $page = $this->page();
        self::assertSame(100, $parser->score($page->texts()));
        self::assertSame(50, (new IngV2PdfParser())->score($page->texts()), 'the Rezumat block must keep V2 below the threshold');

        $dispatcher = new PdfStatementDispatcher([new IngV2PdfParser(), $parser]);
        [$s] = $dispatcher->parse([$page]);

        self::assertSame('ing', $s->bankKey);
        self::assertSame('ING Bank', $s->bankLabel);
        self::assertSame('RO09INGB0000999900001234', $s->iban);
        self::assertSame('RON', $s->currency);
        self::assertSame('EXEMPLU COM SRL', $s->accountHolder);
        self::assertSame('12345678', $s->fiscalCode);
        self::assertSame('1000.00', $s->openingBalance);
        self::assertSame('1950.50', $s->closingBalance);
        self::assertSame([], $s->warnings);
        self::assertCount(2, $s->transactions);

        [$a, $b] = $s->transactions;
        self::assertSame('2024-02-01', $a->date->format('Y-m-d'));
        self::assertSame('250.00', $a->debit);
        self::assertSame('0.00', $a->credit);
        self::assertSame('1234567890', $a->reference);
        self::assertSame('Plata factura F1234 Beneficiar: FURNIZOR TEST SRL', $a->description);
        self::assertSame('FURNIZOR TEST SRL', $a->counterpartyName);
        self::assertSame('750.00', $a->balance);

        self::assertSame('2024-02-05', $b->date->format('Y-m-d'));
        self::assertSame('1200.50', $b->credit);
        self::assertSame('0.00', $b->debit);
        self::assertSame('ABC-77', $b->reference);
        self::assertSame('Incasare client', $b->description);
        self::assertNull($b->counterpartyName);
        self::assertSame('1950.50', $b->balance);
    }

    public function testScoreIsLowForOtherBanks(): void
    {
        $parser = new IngPdfParser();
        self::assertSame(0, $parser->score(['Banca', 'Transilvania', 'Extras', 'de', 'cont']));
        // Without "Rezumat"/"Summary" this layout is never selected.
        self::assertSame(0, $parser->score(['ING', 'Bank', 'N.V.', 'Amsterdam', 'Aviator', 'Popisteanu', 'Numar', 'extras', 'cont']));
        self::assertSame(50, $parser->score(['Rezumat', 'www.ing.ro']));
        self::assertSame(50, $parser->score(['Summary', 'Aviator', 'Popisteanu']));
    }
}
