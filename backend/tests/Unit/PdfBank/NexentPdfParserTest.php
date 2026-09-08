<?php

namespace App\Tests\Unit\PdfBank;

use App\Service\Borderou\Pdf\Bank\NexentPdfParser;
use App\Service\Borderou\Pdf\PdfPage;
use App\Service\Borderou\Pdf\PdfStatementDispatcher;
use PHPUnit\Framework\TestCase;

class NexentPdfParserTest extends TestCase
{
    /** Places [offset, text] pairs on one line (byte offsets, 5pt each: WordFixture derives x from byte offsets). */
    private static function row(array $parts): string
    {
        $buf = str_repeat(' ', 120);
        foreach ($parts as [$offset, $text]) {
            $buf = substr($buf, 0, $offset) . $text . substr($buf, $offset + strlen($text));
        }

        return rtrim($buf);
    }

    /**
     * Reconstructed from the layout description; not a real statement.
     * Everything sits in the lower half of the page (the parser only reads 50 < Top < 520);
     * the client block is placed in the Top 300..380 band where the layout keeps it.
     */
    private function page(): PdfPage
    {
        return WordFixture::fromLayout([
            'Nexent Bank N.V. Amsterdam - Sucursala Bucuresti',                            // 0  (y 330)
            'Bd. Timișoara nr. 26Z, Bucuresti',                                            // 1
            'www.nexentbank.ro',                                                           // 2
            'Extras de cont',                                                              // 3
            'Perioada: 01.03.2025 - 31.03.2025',                                           // 4
            'Pagina 1 din 1',                                                              // 5
            'Cont curent',                                                                 // 6
            'Numar extras: 3',                                                             // 7
            self::row([[0, 'Sold initial'], [84, '1,000.00']]),                            // 8
            '',
            '',
            self::row([[8, 'Nume client: EXEMPLU SRL'], [42, 'Moneda: RON'], [60, 'RO49AAAA1B31007593840000']]), // 9 (y 462)
            self::row([[0, 'Data operarii'], [18, 'Data valutei'], [33, 'Referinta'], [49, 'Explicatii'], [84, 'Suma DB'], [94, 'Suma CR'], [106, 'Sold']]),
            self::row([[0, '(zi.luna.an)'], [18, '(zi.luna.an)']]),
            self::row([[0, '05.03.2025'], [18, '05.03.2025'], [33, 'TRF/123456789'], [49, 'Plata factura FV 12'], [84, '250.00'], [106, '750.00']]),
            self::row([[49, 'catre FURNIZOR EXEMPLU SRL']]),
            self::row([[0, '06.03.2025'], [18, '06.03.2025'], [33, 'INC/987654321'], [49, 'Incasare de la CLIENT EXEMPLU SRL'], [97, '500.00'], [106, '1,250.00']]),
            self::row([[106, '1,250.00']]),
            self::row([[0, 'Sold final'], [106, '1,250.00']]),
        ], 1, 5.0, 12.0, 330.0);
    }

    public function testDetectsAndParses(): void
    {
        $parser = new NexentPdfParser();
        $page = $this->page();
        self::assertSame(100, $parser->score($page->texts()));

        $dispatcher = new PdfStatementDispatcher([$parser]);
        [$s] = $dispatcher->parse([$page]);

        self::assertSame('nexent', $s->bankKey);
        self::assertSame('Nexent Bank', $s->bankLabel);
        self::assertSame('RO49AAAA1B31007593840000', $s->iban);
        self::assertSame('RON', $s->currency);
        self::assertSame('EXEMPLU SRL', $s->accountHolder);
        self::assertNull($s->fiscalCode);
        self::assertSame('1000.00', $s->openingBalance);
        self::assertSame('1250.00', $s->closingBalance);
        self::assertSame([], $s->warnings);
        self::assertCount(2, $s->transactions);

        [$a, $b] = $s->transactions;
        self::assertSame('2025-03-05', $a->date->format('Y-m-d'));
        self::assertSame('2025-03-05', $a->valueDate?->format('Y-m-d'));
        self::assertSame('250.00', $a->debit);
        self::assertSame('0.00', $a->credit);
        self::assertSame('750.00', $a->balance);
        self::assertSame('123456789', $a->reference);
        self::assertSame('Plata factura FV 12 catre FURNIZOR EXEMPLU SRL', $a->description);
        self::assertSame('FURNIZOR EXEMPLU SRL', $a->counterpartyName);
        self::assertCount(2, $a->rawLines);

        self::assertSame('2025-03-06', $b->date->format('Y-m-d'));
        self::assertSame('500.00', $b->credit);
        self::assertSame('0.00', $b->debit);
        self::assertSame('1250.00', $b->balance);
        self::assertSame('987654321', $b->reference);
        self::assertSame('Incasare de la CLIENT EXEMPLU SRL', $b->description);
        self::assertSame('CLIENT EXEMPLU SRL', $b->counterpartyName);
    }

    public function testScoreIsLowForOtherBanks(): void
    {
        $parser = new NexentPdfParser();
        self::assertSame(0, $parser->score(['ING', 'Bank', 'Extras', 'de', 'cont']));
        self::assertSame(50, $parser->score(['Nexent', 'Bank', 'N.V.', 'Amsterdam']));
        self::assertSame(100, $parser->score(['Nexent', 'Bank', 'N.V.', 'Amsterdam', 'Bd.', 'Timișoara', 'nr.', '26Z']));
    }
}
