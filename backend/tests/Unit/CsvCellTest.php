<?php

namespace App\Tests\Unit;

use App\Service\Export\CsvCell;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CsvCellTest extends TestCase
{
    public static function formulaCells(): iterable
    {
        yield 'equals' => ['=1+1', "'=1+1"];
        yield 'equals with function' => ['=HYPERLINK("http://evil")', "'=HYPERLINK(\"http://evil\")"];
        yield 'plus non-numeric' => ['+cmd|/C calc', "'+cmd|/C calc"];
        yield 'minus non-numeric' => ['-2+3+cmd', "'-2+3+cmd"];
        yield 'at' => ['@SUM(A1)', "'@SUM(A1)"];
        yield 'tab' => ["\t=1", "'\t=1"];
        yield 'carriage return' => ["\r=1", "'\r=1"];
    }

    #[DataProvider('formulaCells')]
    public function testFormulaLikeStringsArePrefixed(string $input, string $expected): void
    {
        self::assertSame($expected, CsvCell::neutralize($input));
    }

    public static function safeCells(): iterable
    {
        yield 'plain text' => ['SC Example SRL'];
        yield 'text with formula inside' => ['Total =SUM(A1)'];
        yield 'negative number' => ['-12.50'];
        yield 'positive number with sign' => ['+40'];
        yield 'plain number' => ['100'];
        yield 'empty string' => [''];
        yield 'null' => [null];
        yield 'int' => [42];
        yield 'float' => [-1.5];
        yield 'bool' => [true];
    }

    #[DataProvider('safeCells')]
    public function testSafeValuesAreUntouched(mixed $input): void
    {
        self::assertSame($input, CsvCell::neutralize($input));
    }

    public function testNeutralizeRowPreservesKeysAndOrder(): void
    {
        $row = ['a' => '=1', 'b' => 'ok', 'c' => null, 'd' => '-5'];

        self::assertSame(
            ['a' => "'=1", 'b' => 'ok', 'c' => null, 'd' => '-5'],
            CsvCell::neutralizeRow($row)
        );
    }
}
