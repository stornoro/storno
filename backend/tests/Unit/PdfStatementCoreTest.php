<?php

namespace App\Tests\Unit;

use App\Service\Borderou\Pdf\AbstractPdfStatementParser;
use App\Service\Borderou\Pdf\LineClusterer;
use App\Service\Borderou\Pdf\PdfStatement;
use App\Service\Borderou\Pdf\PdfStatementDispatcher;
use App\Service\Borderou\Pdf\PdfStatementTransaction;
use App\Service\Borderou\Pdf\PdfWord;
use App\Service\Borderou\Pdf\PdfWordExtractor;
use App\Service\Borderou\Parser\PdfBankStatementParser;
use App\Service\Import\Parser\PdfStatementFileParser;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the shared PDF statement toolbox end to end with a hand-made
 * statement PDF and a minimal demo bank parser built on the abstract class.
 */
class PdfStatementCoreTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../Fixtures/borderou/demo-statement.pdf';

    private function demoParser(): AbstractPdfStatementParser
    {
        return new class extends AbstractPdfStatementParser {
            public function getBankKey(): string { return 'demo'; }
            public function getBankLabel(): string { return 'Banca Demo'; }

            public function score(array $page1Words): int
            {
                return $this->scoreByPhrases($page1Words, ['banca demo' => 70, 'extras de cont' => 20]);
            }

            public function parse(array $pages): array
            {
                $lines = $this->lines($pages[0]);
                $iban = $this->findIban($lines, 'IBAN');
                $currency = 'RON';
                $closing = null;
                foreach ($lines as $line) {
                    $t = $this->lineText($line);
                    if ($c = $this->extractCurrency($t)) { $currency = $c; }
                    if ($v = $this->valueAfterLabel($t, 'Sold final')) { $closing = $this->parseAmount($v); }
                }
                $h = $this->findHeaderLine($lines, ['Data', 'Descriere', 'Debit', 'Credit']);
                $columns = $this->locateColumns($lines[$h], ['date' => 'Data', 'desc' => 'Descriere', 'debit' => 'Debit', 'credit' => 'Credit']);
                $bounds = $this->columnBounds($columns);
                $txs = [];
                for ($i = $h + 1; $i < count($lines); $i++) {
                    $cells = $this->assignColumns($lines[$i], $bounds);
                    $date = $this->parseDate($cells['date']);
                    if (!$date) { continue; }
                    $txs[] = new PdfStatementTransaction(
                        $date,
                        $cells['desc'],
                        $this->absAmount($this->parseAmount($cells['debit'])),
                        $this->absAmount($this->parseAmount($cells['credit'])),
                    );
                }
                $warning = $this->reconcile('0.00', $closing, $txs);

                return [new PdfStatement('demo', 'Banca Demo', $iban, $currency, $txs, closingBalance: $closing, warnings: array_filter([$warning]))];
            }
        };
    }

    public function testLineClustererGroupsByBaselineAndOrdersLeftToRight(): void
    {
        $words = [
            new PdfWord('b', 100, 10, 110, 20),
            new PdfWord('a', 10, 11, 20, 21),
            new PdfWord('c', 10, 40, 20, 50),
        ];
        $lines = (new LineClusterer())->cluster($words);
        self::assertCount(2, $lines);
        self::assertSame('a b', LineClusterer::text($lines[0]));
        self::assertSame('c', LineClusterer::text($lines[1]));
    }

    /**
     * @dataProvider amounts
     */
    public function testParseAmount(string $input, ?string $expected): void
    {
        $parser = $this->demoParser();
        $m = new \ReflectionMethod($parser, 'parseAmount');
        self::assertSame($expected, $m->invoke($parser, $input));
    }

    public static function amounts(): iterable
    {
        yield ['1.234,56', '1234.56'];
        yield ['1,234.56', '1234.56'];
        yield ['1234.56', '1234.56'];
        yield ['1234,5', '1234.50'];
        yield ['-1.234,56', '-1234.56'];
        yield ['1.234,56-', '-1234.56'];
        yield ['(1.234,56)', '-1234.56'];
        yield ['1 234,56', '1234.56'];
        yield ['RON 12,00', '12.00'];
        yield ['12.345', '12345.00'];
        yield ['', null];
        yield ['abc', null];
    }

    public function testEndToEndWithHandMadePdf(): void
    {
        $extractor = new PdfWordExtractor();
        $pages = $extractor->extract(self::FIXTURE);
        self::assertCount(1, $pages);
        self::assertGreaterThan(20, count($pages[0]->words));

        $dispatcher = new PdfStatementDispatcher([$this->demoParser()]);
        $statements = $dispatcher->parse($pages);
        self::assertCount(1, $statements);
        $s = $statements[0];
        self::assertSame('RO49AAAA1B31007593840000', $s->iban);
        self::assertSame('RON', $s->currency);
        self::assertSame('1299.50', $s->closingBalance);
        self::assertCount(2, $s->transactions);
        self::assertSame('2026-03-01', $s->transactions[0]->date->format('Y-m-d'));
        self::assertSame('1500.00', $s->transactions[0]->credit);
        self::assertSame('Incasare factura 123 ACME SRL', $s->transactions[0]->description);
        self::assertSame('200.50', $s->transactions[1]->debit);
        self::assertSame([], $s->warnings, 'balances reconcile');

        // File parser + borderou parser: only the credit becomes a transaction
        $fileParser = new PdfStatementFileParser($extractor, $dispatcher);
        $preview = $fileParser->preview(self::FIXTURE, 5);
        self::assertSame('demo', $preview['metadata']['bank']);
        self::assertSame('RO49AAAA1B31007593840000', $preview['metadata']['Numar cont']);
        $bord = new PdfBankStatementParser();
        $bord->setMetadata($preview['metadata']);
        self::assertSame(1.0, $bord->detectConfidence($preview['headers']));
        $rows = $bord->parseRows($preview['headers'], $fileParser->parse(self::FIXTURE));
        self::assertCount(1, $rows);
        self::assertSame('1500.00', $rows[0]['amount']);
        self::assertSame('123', $rows[0]['documentNumber']);
        self::assertSame('ACME SRL', $rows[0]['clientName']);
        self::assertSame('RO49AAAA1B31007593840000', $bord->extractIban($fileParser->parse(self::FIXTURE)));
    }

    public function testDispatcherRejectsUnknownLayout(): void
    {
        $this->expectException(\App\Service\Borderou\Pdf\PdfStatementNotRecognizedException::class);
        $dispatcher = new PdfStatementDispatcher([$this->demoParser()]);
        $dispatcher->parse([new \App\Service\Borderou\Pdf\PdfPage(1, [new PdfWord('altceva', 0, 0, 10, 10)])]);
    }

    public function testSmalotFallbackExtractsWordsToo(): void
    {
        $extractor = new PdfWordExtractor('/nonexistent/pdftotext');
        $pages = $extractor->extract(self::FIXTURE);
        self::assertCount(1, $pages);
        $texts = $pages[0]->texts();
        self::assertContains('Descriere', $texts);
        self::assertContains('1.500,00', $texts);
    }
}
