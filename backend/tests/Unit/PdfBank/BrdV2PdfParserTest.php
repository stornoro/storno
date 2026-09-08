<?php

namespace App\Tests\Unit\PdfBank;

use App\Service\Borderou\Pdf\Bank\BrdV2PdfParser;
use App\Service\Borderou\Pdf\PdfStatementDispatcher;
use PHPUnit\Framework\TestCase;

/** Reconstructed BRD bilingual layout; placeholder names and synthetic IBANs. */
class BrdV2PdfParserTest extends TestCase
{
    private function page(): \App\Service\Borderou\Pdf\PdfPage
    {
        return WordFixture::fromLayout([
            ' BRD Groupe Societe Generale                      Acest extras de cont este valabil fara semnatura',
            ' Detinator cont / Account Holder                  Numar cont / Account Number',
            ' EXEMPLU COM SRL                                  RO07BRDE0000999900001234    Cont curent RON',
            ' De la data de / Date from 01.02.2024  pana la / to 29.02.2024',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            ' Sold initial / Opening balance: 1.000,00         Sold final / Closing balance: 1.950,50',
            '      Data inregistrarii Detalii tranzactie         Beneficiar / Platitor Referinta client  Suma',
            '      Data valutei                                  CUI / CNP             Bank reference',
            '                                                    IBAN',
            '      01.02.2024        Plata factura F1234         FURNIZOR TEST SRL     REF-0001          -250,00',
            '      01.02.2024        comision                    12345678',
            '                                                    RO78BTRL0000999900001234',
            '      05.02.2024        Incasare client             CLIENT TEST SRL       REF-0002          1.200,50',
            '      05.02.2024',
        ]);
    }

    public function testDetectsAndParses(): void
    {
        $parser = new BrdV2PdfParser();
        $page = $this->page();
        self::assertSame(100, $parser->score($page->texts()));

        [$s] = (new PdfStatementDispatcher([$parser]))->parse([$page]);
        self::assertSame('brd', $s->bankKey);
        self::assertSame('RO07BRDE0000999900001234', $s->iban);
        self::assertSame('RON', $s->currency);
        self::assertSame('EXEMPLU COM SRL', $s->accountHolder);
        self::assertSame('1000.00', $s->openingBalance);
        self::assertSame('1950.50', $s->closingBalance);
        self::assertSame([], $s->warnings);
        self::assertCount(2, $s->transactions);
        [$a, $b] = $s->transactions;
        self::assertSame('2024-02-01', $a->date->format('Y-m-d'));
        self::assertSame('250.00', $a->debit);
        self::assertSame('REF-0001', $a->reference);
        self::assertSame('FURNIZOR TEST SRL', $a->counterpartyName);
        self::assertSame('RO78BTRL0000999900001234', $a->counterpartyIban);
        self::assertStringStartsWith('Plata factura F1234 comision', $a->description);
        self::assertSame('2024-02-05', $b->date->format('Y-m-d'));
        self::assertSame('1200.50', $b->credit);
        self::assertSame('REF-0002', $b->reference);
        self::assertSame('CLIENT TEST SRL', $b->counterpartyName);
        self::assertSame('1950.50', $b->balance);
    }

    public function testClassicLayoutScoresBelowThreshold(): void
    {
        self::assertSame(50, (new BrdV2PdfParser())->score(['BRD-Groupe', 'Societe', 'Generale', 'Mihalache', 'Domicilierea']));
    }
}
