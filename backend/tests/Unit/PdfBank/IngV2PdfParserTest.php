<?php

namespace App\Tests\Unit\PdfBank;

use App\Service\Borderou\Pdf\Bank\IngPdfParser;
use App\Service\Borderou\Pdf\Bank\IngV2PdfParser;
use App\Service\Borderou\Pdf\PdfPage;
use App\Service\Borderou\Pdf\PdfStatementDispatcher;
use PHPUnit\Framework\TestCase;

class IngV2PdfParserTest extends TestCase
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
        // Header block = right half of the page (Right > 340): offset 76 => x 380.
        return WordFixture::fromLayout([
            self::row([0 => 'ING Bank N.V. Amsterdam Sucursala Bucuresti, Bd. Aviator Popisteanu', 76 => 'Cont curent RON']),
            self::row([76 => 'RO09 INGB 0000 9999 0000 1234']),
            self::row([76 => 'EXEMPLU COM SRL | CUI 12345678']),
            self::row([0 => 'Sold initial', 24 => 'Sold final', 48 => 'Perioada']),
            self::row([0 => '1,000.00 RON | 1,950.50 RON 01.02.2024 - 29.02.2024']),
            '',
            self::row([0 => 'Data procesarii', 18 => 'Beneficiar / Detalii tranzactie', 66 => 'Debitari', 79 => 'Creditari', 92 => 'Sold']),
            self::row([18 => 'Referinta']),
            self::row([0 => '01.02.2024', 18 => 'FURNIZOR TEST SRL', 66 => '250.00', 92 => '750.00']),
            self::row([18 => 'Plata factura F1234']),
            self::row([18 => 'Referinta bancii: 1234567890']),
            self::row([0 => '05.02.2024', 18 => 'CLIENT TEST SRL', 79 => '1,200.50', 92 => '1,950.50']),
            self::row([18 => 'Incasare F2000 Referinta interna: ABC-77']),
        ], 1, 5.0, 12.0, 100.0);
    }

    public function testDetectsAndParses(): void
    {
        $parser = new IngV2PdfParser();
        $page = $this->page();
        self::assertSame(100, $parser->score($page->texts()));
        self::assertSame(0, (new IngPdfParser())->score($page->texts()), 'no Rezumat block => V1 must not match');

        $dispatcher = new PdfStatementDispatcher([new IngPdfParser(), $parser]);
        [$s] = $dispatcher->parse([$page]);

        self::assertSame('ing', $s->bankKey);
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
        self::assertSame('FURNIZOR TEST SRL Plata factura F1234', $a->description);
        self::assertSame('750.00', $a->balance);

        self::assertSame('2024-02-05', $b->date->format('Y-m-d'));
        self::assertSame('1200.50', $b->credit);
        self::assertSame('0.00', $b->debit);
        self::assertSame('ABC-77', $b->reference);
        self::assertSame('CLIENT TEST SRL Incasare F2000', $b->description);
        self::assertSame('1950.50', $b->balance);
    }

    public function testScoreIsLowForOtherBanks(): void
    {
        $parser = new IngV2PdfParser();
        self::assertSame(0, $parser->score(['Banca', 'Transilvania', 'Extras', 'de', 'cont']));
        // A statement number or a Rezumat block belongs to the V1 layout.
        self::assertSame(50, $parser->score(['ING', 'Bank', 'N.V.', 'Amsterdam', 'Aviator', 'Popisteanu', 'Numar', 'extras', 'cont']));
        self::assertSame(50, $parser->score(['ING', 'Bank', 'N.V.', 'Amsterdam', 'Aviator', 'Popisteanu', 'Rezumat']));
        self::assertSame(50, $parser->score(['www.ing.ro']));
    }
}
