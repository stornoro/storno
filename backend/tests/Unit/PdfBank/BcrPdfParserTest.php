<?php

namespace App\Tests\Unit\PdfBank;

use App\Service\Borderou\Pdf\Bank\BcrPdfParser;
use App\Service\Borderou\Pdf\PdfStatementDispatcher;
use PHPUnit\Framework\TestCase;

/**
 * Layout reconstructed from the George-era BCR statement (per-day blocks, Romanian amounts).
 * Names, IBANs and codes are placeholders.
 */
class BcrPdfParserTest extends TestCase
{
    private function page(): \App\Service\Borderou\Pdf\PdfPage
    {
        return WordFixture::fromLayout([
            '                                  BANCA COMERCIALA ROMANA S.A.',
            '                                  Cladirea The Bridge 1, Sector 6',
            '                                  SWIFT: RNCB RO BU   Site: www.bcr.ro, Email: contact.center@bcr.ro',
            '           EXTRAS DE CONT Nr. 9 din data: 18-08-2026',
            ' Cod IBAN Nou: RO49AAAA1B31007593840000',
            ' Produse in valuta RON',
            ' Titular:  EXEMPLU COM SRL                        CIC: 12345678       CUI/CNP: 12345678',
            '',
            ' Data:     05-07-2026                                         Sold contabil initial:        1.000,00',
            ' Data operatiunii Explicatie                           Referinta Oper.        Debit         Credit',
            ' Data Valorii                                          Document',
            ' Tranzactii finalizate:',
            ' 05-07-2026 12:02 Plata factura F1234 -Beneficiar: FURNIZOR 2026070585790849  250,00        0,00',
            '                 TEST SRL; RO49AAAA1B31007593840001; CODFISC Ordin de plata',
            '                 12345-Detalii: factura 1234           04.07.2026',
            '                                                  Tranzactii finalizate:      250,00        0,00',
            '                                                  Sold contabil final:                      750,00',
            ' Data:     06-07-2026                                         Sold contabil initial:        750,00',
            ' Data operatiunii Explicatie                           Referinta Oper.        Debit         Credit',
            ' Data Valorii                                          Document',
            ' Tranzactii finalizate:',
            ' 06-07-2026 09:00 Incasare -Platitor: CLIENT TEST SRL; 2026070691839650       0,00          1.200,50',
            '                 RO49AAAA1B31007593840002; CODFISC 99999- Ordin de plata',
            '                 Detalii: plata factura 77             06.07.2026',
            '                                                  Tranzactii finalizate:      0,00          1.200,50',
            '                                                  Sold contabil final:                      1.950,50',
            ' Total tranzactii finalizate pe perioada:    01-07-2026 - 31-07-2026          250,00        1.200,50',
            ' Sold contabil final la:                     31-07-2026                                     1.950,50',
            ' Sold disponibil la:                         18-08-2026                                     1.950,50',
            ' PREZENTUL DOCUMENT ESTE ELIBERAT DE BANCA COMERCIALA ROMANA',
            '                                        Pagina 1 din 1',
        ]);
    }

    public function testDetectsAndParses(): void
    {
        $parser = new BcrPdfParser();
        $page = $this->page();
        self::assertSame(100, $parser->score($page->texts()));

        [$s] = (new PdfStatementDispatcher([$parser]))->parse([$page]);
        self::assertSame('bcr', $s->bankKey);
        self::assertSame('RO49AAAA1B31007593840000', $s->iban);
        self::assertSame('RON', $s->currency);
        self::assertSame('EXEMPLU COM SRL', $s->accountHolder);
        self::assertSame('12345678', $s->fiscalCode);
        self::assertSame('1000.00', $s->openingBalance);
        self::assertSame('1950.50', $s->closingBalance);
        self::assertSame([], $s->warnings);
        self::assertCount(2, $s->transactions);

        [$a, $b] = $s->transactions;
        self::assertSame('2026-07-05', $a->date->format('Y-m-d'));
        self::assertSame('250.00', $a->debit);
        self::assertSame('2026070585790849', $a->reference);
        self::assertSame('2026-07-04', $a->valueDate?->format('Y-m-d'));
        self::assertSame('FURNIZOR TEST SRL', $a->counterpartyName);
        self::assertSame('RO49AAAA1B31007593840001', $a->counterpartyIban);
        self::assertSame('750.00', $a->balance);

        self::assertSame('2026-07-06', $b->date->format('Y-m-d'));
        self::assertSame('1200.50', $b->credit);
        self::assertSame('CLIENT TEST SRL', $b->counterpartyName);
        self::assertSame('RO49AAAA1B31007593840002', $b->counterpartyIban);
        self::assertSame('1950.50', $b->balance);
    }

    public function testScoreIsLowForOtherBanks(): void
    {
        self::assertSame(0, (new BcrPdfParser())->score(['Banca', 'Transilvania', 'extras']));
    }
}
