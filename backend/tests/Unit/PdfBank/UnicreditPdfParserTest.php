<?php

namespace App\Tests\Unit\PdfBank;

use App\Service\Borderou\Pdf\Bank\UnicreditPdfParser;
use App\Service\Borderou\Pdf\Bank\UnicreditV2PdfParser;
use App\Service\Borderou\Pdf\PdfPage;
use App\Service\Borderou\Pdf\PdfStatementDispatcher;
use PHPUnit\Framework\TestCase;

class UnicreditPdfParserTest extends TestCase
{
    /** Reconstructed from the layout description; not a real statement. */
    private function page(): PdfPage
    {
        return WordFixture::fromLayout([
            'UniCredit Bank S.A.   Bulevardul Expoziției nr. 1F   www.unicredit.ro',
            'Denumire companie: DEMO EXEMPLU SRL',
            'IBAN: RO00 BACX 0000 0000 0000 0000',
            'Moneda: RON',
            '',
            'Sumar cont',
            'Sold initial sume debitate sume creditate Sold final',
            '10,000.00 1,250.00 3,400.50 12,150.50',
            '',
            'Tranzactii',
            'Data              Descriere                                  Debit       Credit',
            '03 aprilie 2024   PLATA FURNIZOR DEMO SRL                    1,250.00',
            '                  Factura FF-2024-0101 ref 123456789012',
            '05 aprilie 2024   INCASARE CLIENT DEMO SRL                               3,400.50',
            '                  Factura FF-2024-0099',
            'Fondurile disponibile pot fi diferite de sold',
        ]);
    }

    public function testDetectsAndParses(): void
    {
        $parser = new UnicreditPdfParser();
        $page = $this->page();
        self::assertSame(100, $parser->score($page->texts()));
        self::assertSame(50, (new UnicreditV2PdfParser())->score($page->texts()));

        [$s] = (new PdfStatementDispatcher([$parser, new UnicreditV2PdfParser()]))->parse([$page]);

        self::assertSame('unicredit', $s->bankKey);
        self::assertSame('RO00BACX0000000000000000', $s->iban);
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
        self::assertSame('PLATA FURNIZOR DEMO SRL Factura FF-2024-0101 ref 123456789012', $a->description);
        self::assertSame('123456789012', $a->reference);
        self::assertSame('8750.00', $a->balance);

        self::assertSame('2024-04-05', $b->date->format('Y-m-d'));
        self::assertSame('3400.50', $b->credit);
        self::assertSame('INCASARE CLIENT DEMO SRL Factura FF-2024-0099', $b->description);
        self::assertNull($b->reference);
        self::assertSame('12150.50', $b->balance);
    }

    public function testDoubledBoldGlyphsInLabelsAreTolerated(): void
    {
        $page = WordFixture::fromLayout([
            'UniCredit Bank S.A.   Bulevardul Expoziției nr. 1F   www.unicredit.ro',
            'Denumire companie: DEMO EXEMPLU SRL',
            'IBAN: RO00 BACX 0000 0000 0000 0000',
            'Moneda: RON',
            '',
            'SSuummaarr ccoonntt',
            'SSoolldd iinniittiiaall ssuummee ddeebbiittaattee ssuummee ccrreeddiittaattee SSoolldd ffiinnaall',
            '10,000.00 1,250.00 3,400.50 12,150.50',
            '',
            'TTrraannzzaaccttiiii',
            self::cols([[0, 'DDaattaa'], [18, 'DDeessccrriieerree'], [61, 'DDeebbiitt'], [73, 'CCrreeddiitt']]),
            self::cols([[0, '03 aprilie 2024'], [18, 'PLATA FURNIZOR DEMO SRL'], [61, '1,250.00']]),
            self::cols([[0, '05 aprilie 2024'], [18, 'INCASARE CLIENT DEMO SRL'], [73, '3,400.50']]),
        ]);
        $parser = new UnicreditPdfParser();
        self::assertSame(100, $parser->score($page->texts()));
        [$s] = $parser->parse([$page]);
        self::assertSame([], $s->warnings);
        self::assertCount(2, $s->transactions);
        self::assertSame('12150.50', $s->closingBalance);
    }

    /**
     * @param array<int, array{0: int, 1: string}> $cells [column offset, text]
     */
    private static function cols(array $cells): string
    {
        $row = '';
        foreach ($cells as [$col, $text]) {
            $row = str_pad($row, $col) . $text;
        }

        return $row;
    }

    public function testScoreIsLowForOtherBanks(): void
    {
        $parser = new UnicreditPdfParser();
        self::assertSame(0, $parser->score(['ING', 'Bank', 'Extras', 'de', 'cont']));
        self::assertSame(50, $parser->score(['UniCredit', 'Bank', 'S.A.']));
    }
}
