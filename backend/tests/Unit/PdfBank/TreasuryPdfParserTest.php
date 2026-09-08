<?php

namespace App\Tests\Unit\PdfBank;

use App\Service\Borderou\Pdf\Bank\TreasuryPdfParser;
use App\Service\Borderou\Pdf\PdfStatementDispatcher;
use App\Service\Borderou\Pdf\PdfWordExtractor;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;

class TreasuryPdfParserTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../../Fixtures/borderou/trezorerie-sample.pdf';

    /** Values invented; not a real statement. */
    private const XML = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<extras Data_extras="20240603" Denumire_EP="EXEMPLU SRL" Nr_ext="12">
  <cont_ext>
    <cont Cod_IBAN="RO00TREZ0000000000000000" Nr_op="2">5070XXXX</cont>
    <Sold_precedent><Sumad>0</Sumad><Sumac>11500.00</Sumac></Sold_precedent>
    <cont_misc><nrdoc>15</nrdoc><datadoc>20240603</datadoc><databan>20240603</databan>
      <nrrefdest>OP15</nrrefdest><ibanbfpl>RO00BANK0000000000000001</ibanbfpl><platitor>11111111</platitor><numepb>FURNIZOR SRL</numepb>
      <sumad>1250.00</sumad><sumac>0</sumac><explicatii>PLATA FACTURA 123</explicatii></cont_misc>
    <cont_misc><nrdoc>16</nrdoc><datadoc>20240602</datadoc><databan>20240603</databan>
      <nrrefdest>OP16</nrrefdest><ibanbfpl/><platitor>22222222</platitor><numepb>CLIENT  SRL</numepb>
      <sumad>0</sumad><sumac>3000.00</sumac><explicatii>INCASARE FACTURA 77</explicatii></cont_misc>
    <Rulaj_zi><Sumad>1250.00</Sumad><Sumac>3000.00</Sumac></Rulaj_zi>
    <Sold_final><Sumad>0</Sumad><Sumac>13250.00</Sumac></Sold_final>
  </cont_ext>
  <cont_ext>
    <cont Cod_IBAN="RO00TREZ0000000000000001" Nr_op="1">5070YYYY</cont>
    <Sold_precedent><Sumad>0</Sumad><Sumac>100.00</Sumac></Sold_precedent>
    <Sold_final><Sumad>0</Sumad><Sumac>100.00</Sumac></Sold_final>
  </cont_ext>
</extras>
XML;

    public function testScore(): void
    {
        $parser = new TreasuryPdfParser();
        self::assertSame(100, $parser->score(['Trezorerie', 'Municipiul', 'X', 'EXTRAS', 'DE', 'CONT', 'Intocmit', 'si', 'Verificat,']));
        self::assertSame(60, $parser->score(['Trezorerie', 'Municipiul', 'X']));
        self::assertSame(60, $parser->score(['Intocmit', 'si', 'Verificat,']));
        self::assertSame(0, $parser->score(['ING', 'Bank', 'Extras', 'de', 'cont']));
        self::assertSame('trezorerie', $parser->getBankKey());
        self::assertSame('Trezoreria Statului', $parser->getBankLabel());
    }

    public function testParsesEmbeddedXml(): void
    {
        $statements = (new TreasuryPdfParser())->parseXml(self::XML);
        self::assertCount(2, $statements);
        [$s, $s2] = $statements;

        self::assertSame('trezorerie', $s->bankKey);
        self::assertSame('RO00TREZ0000000000000000', $s->iban);
        self::assertSame('RON', $s->currency);
        self::assertSame('EXEMPLU SRL', $s->accountHolder);
        self::assertNull($s->fiscalCode);
        self::assertSame('11500.00', $s->openingBalance);
        self::assertSame('13250.00', $s->closingBalance);
        self::assertSame('2024-06-03', $s->periodStart?->format('Y-m-d'));
        self::assertSame([], $s->warnings);
        self::assertCount(2, $s->transactions);

        [$a, $b] = $s->transactions;
        self::assertSame('2024-06-03', $a->date->format('Y-m-d'));
        self::assertSame('1250.00', $a->debit);
        self::assertSame('0.00', $a->credit);
        self::assertSame('OP15', $a->reference);
        self::assertSame('Cod Fiscal 11111111_PLATA FACTURA 123', $a->description);
        self::assertSame('FURNIZOR SRL', $a->counterpartyName);
        self::assertSame('RO00BANK0000000000000001', $a->counterpartyIban);
        self::assertSame('10250.00', $a->balance);

        self::assertSame('2024-06-03', $b->date->format('Y-m-d'));
        self::assertSame('2024-06-02', $b->valueDate?->format('Y-m-d'));
        self::assertSame('3000.00', $b->credit);
        self::assertSame('OP16', $b->reference);
        self::assertSame('Cod Fiscal 22222222_INCASARE FACTURA 77', $b->description);
        self::assertSame('CLIENT SRL', $b->counterpartyName);
        self::assertNull($b->counterpartyIban);
        self::assertSame('13250.00', $b->balance);

        self::assertSame('RO00TREZ0000000000000001', $s2->iban);
        self::assertSame('100.00', $s2->openingBalance);
        self::assertSame([], $s2->transactions);
        self::assertSame(['Nu a fost gasita nicio tranzactie in extras.'], $s2->warnings);
    }

    public function testClosingMismatchIsAWarning(): void
    {
        $xml = str_replace('<Sumac>13250.00</Sumac></Sold_final>', '<Sumac>13000.00</Sumac></Sold_final>', self::XML);
        [$s] = (new TreasuryPdfParser())->parseXml($xml);
        self::assertCount(1, $s->warnings);
        self::assertStringContainsString('nu corespunde', $s->warnings[0]);
        self::assertCount(2, $s->transactions);
    }

    public function testRealFixtureViaXmlAttachment(): void
    {
        $finder = new ExecutableFinder();
        if (!$finder->find('pdfdetach') || !$finder->find('pdftotext')) {
            self::markTestSkipped('poppler (pdfdetach/pdftotext) is not installed');
        }
        $parser = new TreasuryPdfParser();
        $pages = (new PdfWordExtractor())->extract(self::FIXTURE);
        self::assertSame(100, $parser->score($pages[0]->texts()));

        $parser->setSourcePath(self::FIXTURE);
        $statements = (new PdfStatementDispatcher([$parser]))->parse($pages);
        self::assertCount(14, $statements);
        foreach ($statements as $s) {
            self::assertSame('trezorerie', $s->bankKey);
            self::assertMatchesRegularExpression('/^RO\d{2}TREZ[A-Z0-9]{16}$/', (string) $s->iban);
            self::assertSame('RON', $s->currency);
            self::assertNotNull($s->accountHolder);
            self::assertNotNull($s->openingBalance);
            self::assertNotNull($s->closingBalance);
            foreach ($s->warnings as $w) {
                self::assertStringNotContainsString('nu corespunde', $w, 'balances must reconcile for ' . $s->iban);
            }
            foreach ($s->transactions as $tx) {
                self::assertSame('2023-12-29', $tx->date->format('Y-m-d'));
                self::assertStringStartsWith('Cod Fiscal ', $tx->description);
                self::assertNotNull($tx->reference);
                self::assertTrue($tx->isCredit() xor $tx->isDebit());
            }
        }
        $withTx = array_filter($statements, static fn ($s) => $s->transactions !== []);
        self::assertGreaterThanOrEqual(10, count($withTx));
    }

    public function testRealFixtureViaPrintedTableFallback(): void
    {
        if (!(new ExecutableFinder())->find('pdftotext')) {
            self::markTestSkipped('poppler (pdftotext) is not installed');
        }
        $pages = (new PdfWordExtractor())->extract(self::FIXTURE);
        $parser = new TreasuryPdfParser('/nonexistent/pdfdetach');
        $parser->setSourcePath(self::FIXTURE);
        $text = $parser->parse($pages);

        $xml = (new TreasuryPdfParser())->parseFile(self::FIXTURE);
        self::assertCount(count($xml), $text);
        foreach ($xml as $i => $expected) {
            $actual = $text[$i];
            self::assertSame($expected->iban, $actual->iban);
            self::assertSame($expected->openingBalance, $actual->openingBalance);
            self::assertSame($expected->closingBalance, $actual->closingBalance);
            self::assertCount(count($expected->transactions), $actual->transactions, 'transaction count for ' . $expected->iban);
            foreach ($expected->transactions as $j => $etx) {
                $atx = $actual->transactions[$j];
                self::assertSame($etx->date->format('Y-m-d'), $atx->date->format('Y-m-d'));
                self::assertSame($etx->debit, $atx->debit);
                self::assertSame($etx->credit, $atx->credit);
                self::assertSame($etx->reference, $atx->reference);
                self::assertSame($etx->description, $atx->description);
                self::assertSame($etx->counterpartyName, $atx->counterpartyName);
                self::assertSame($etx->balance, $atx->balance);
            }
            foreach ($actual->warnings as $w) {
                self::assertStringNotContainsString('nu corespunde', $w);
            }
        }
    }
}
