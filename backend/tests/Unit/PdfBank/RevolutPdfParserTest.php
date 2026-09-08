<?php

namespace App\Tests\Unit\PdfBank;

use App\Service\Borderou\Pdf\Bank\RevolutPdfParser;
use App\Service\Borderou\Pdf\PdfPage;
use App\Service\Borderou\Pdf\PdfStatementDispatcher;
use PHPUnit\Framework\TestCase;

class RevolutPdfParserTest extends TestCase
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

    /** Reconstructed from the layout description; not a real statement. */
    private function page(): PdfPage
    {
        return WordFixture::fromLayout([
            'Account statement',                                                   // 0
            'Generated on 7 Mar 2025',                                             // 1
            'Revolut Bank UAB',                                                    // 2
            'Vilnius Sucursala București',                                         // 3
            'Konstitucijos ave. 21B, Vilnius',                                     // 4
            'Business account details',                                            // 5
            self::row([[8, 'EXEMPLU SRL']]),                                       // 6  holder box
            self::row([[8, 'Str. Exemplu nr. 1']]),                                // 7  holder box
            self::row([[8, 'București, România']]),                                // 8  holder box
            'IBAN RO49 AAAA 1B31 0075 9384 0000',                                  // 9
            'BIC REVOROBB',                                                        // 10
            'Currency EUR',                                                        // 11
            self::row([[0, 'Opening balance'], [31, '€0.00']]),                    // 12
            self::row([[0, 'Closing balance'], [31, '€954.70']]),                  // 13
            self::row([[0, 'Date (UTC)'], [14, 'Description'], [60, 'Money out'], [74, 'Money in'], [87, 'Balance']]),
            self::row([[0, '6 Mar 2025'], [14, 'Card payment to Example Shop'], [60, '€45.30'], [87, '€954.70']]),
            self::row([[14, 'ID: 7c9e6679-7425-40de-944b-e07fc1f90ae7']]),
            self::row([[0, '5 Mar 2025'], [14, 'Payment from EXAMPLE CLIENT SRL'], [74, '€1,000.00'], [87, '€1,000.00']]),
            self::row([[14, 'ID: 0f8fad5b-d9cb-469f-a165-70867728950e']]),
            'Transaction types',
            'Card payment: a payment made with a card',
        ], 1, 5.0, 12.0, 50.0);
    }

    public function testDetectsAndParses(): void
    {
        $parser = new RevolutPdfParser();
        $page = $this->page();
        self::assertSame(100, $parser->score($page->texts()));

        $dispatcher = new PdfStatementDispatcher([$parser]);
        [$s] = $dispatcher->parse([$page]);

        self::assertSame('revolut', $s->bankKey);
        self::assertSame('Revolut', $s->bankLabel);
        self::assertSame('RO49AAAA1B31007593840000', $s->iban);
        self::assertSame('EUR', $s->currency);
        self::assertSame('EXEMPLU SRL', $s->accountHolder);
        self::assertNull($s->fiscalCode);
        self::assertSame('0.00', $s->openingBalance);
        self::assertSame('954.70', $s->closingBalance);
        self::assertSame([], $s->warnings);
        self::assertCount(2, $s->transactions);

        [$a, $b] = $s->transactions;
        self::assertSame('2025-03-05', $a->date->format('Y-m-d'));
        self::assertSame('1000.00', $a->credit);
        self::assertSame('0.00', $a->debit);
        self::assertSame('1000.00', $a->balance);
        self::assertSame('0f8fad5b-d9cb-469f-a165-70867728950e', $a->reference);
        self::assertSame('Payment from EXAMPLE CLIENT SRL', $a->description);
        self::assertSame('EXAMPLE CLIENT SRL', $a->counterpartyName);

        self::assertSame('2025-03-06', $b->date->format('Y-m-d'));
        self::assertSame('45.30', $b->debit);
        self::assertSame('0.00', $b->credit);
        self::assertSame('954.70', $b->balance);
        self::assertSame('7c9e6679-7425-40de-944b-e07fc1f90ae7', $b->reference);
        self::assertSame('Card payment to Example Shop', $b->description);
        self::assertSame('Example Shop', $b->counterpartyName);
        self::assertCount(2, $b->rawLines);
    }

    public function testScoreIsLowForOtherBanks(): void
    {
        $parser = new RevolutPdfParser();
        self::assertSame(0, $parser->score(['ING', 'Bank', 'Extras', 'de', 'cont']));
        self::assertSame(50, $parser->score(['Revolut', 'Bank', 'UAB']));
        self::assertSame(50, $parser->score(['Konstitucijos', 'ave.', '21B,', 'Vilnius,', '08130,', 'the', 'Republic', 'of', 'Lithuania']));
    }
}
