<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\Recurring\TemplateExpression;
use App\Service\RecurringInvoiceProcessor;
use PHPUnit\Framework\TestCase;

final class TemplateExpressionTest extends TestCase
{
    public function testArithmeticWithPrecedenceParenthesesAndNames(): void
    {
        self::assertSame(9633.0, TemplateExpression::evaluate('1900*curs', ['curs' => 5.07]));
        self::assertSame(14.0, TemplateExpression::evaluate('2 + 3 * 4', []));
        self::assertSame(20.0, TemplateExpression::evaluate('(2 + 3) * 4', []));
        self::assertSame(-10.0, TemplateExpression::evaluate('-(2+3)*2', []));
        self::assertSame(2.5, TemplateExpression::evaluate('10 / 4', []));
        self::assertSame(1901.0, TemplateExpression::evaluate('1900,5 + 0.5', []), 'a comma is accepted as the decimal separator');
        self::assertSame(100.0, TemplateExpression::evaluate('sold_anterior - rata', ['sold_anterior' => 300.0, 'rata' => 200.0]));
    }

    public function testNamesListsTheIdentifiersOnce(): void
    {
        self::assertSame(['curs_EUR', 'sold'], TemplateExpression::names('1900*curs_EUR - sold + curs_EUR'));
    }

    /** @dataProvider invalidExpressions */
    public function testInvalidExpressionsAreRejected(string $expression): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TemplateExpression::evaluate($expression, ['curs' => 5.0]);
    }

    public static function invalidExpressions(): iterable
    {
        yield 'dangling operator' => ['1900*'];
        yield 'unknown name' => ['1900*usd'];
        yield 'division by zero' => ['2/0'];
        yield 'function call' => ['exec(1)'];
        yield 'statement separator' => ['2;3'];
        yield 'unclosed parenthesis' => ['(1+2'];
        yield 'empty' => [''];
        yield 'too long' => [str_repeat('1+', 150) . '1'];
    }

    public function testSampleVarsCoverEveryBuiltInName(): void
    {
        $vars = RecurringInvoiceProcessor::sampleExpressionVars();
        self::assertSame(0.0, TemplateExpression::evaluate('curs*0 + sold + sold_anterior + rata', $vars));
    }

    public function testRomanianAmountFormat(): void
    {
        self::assertSame('9.633,00', RecurringInvoiceProcessor::formatAmount(9633.0));
        self::assertSame('0,50', RecurringInvoiceProcessor::formatAmount(0.5));
    }
}
