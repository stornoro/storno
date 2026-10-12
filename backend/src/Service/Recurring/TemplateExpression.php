<?php

declare(strict_types=1);

namespace App\Service\Recurring;

/**
 * A small arithmetic evaluator for the `[[suma:…]]` placeholder and the balance step of recurring
 * templates: numbers, + - * / with the usual precedence, parentheses, unary minus and named values
 * such as `curs`, `curs_EUR`, `sold` or `rata`. Nothing else is accepted, so a template can never
 * run code — an unknown name or a malformed expression is an InvalidArgumentException.
 */
final class TemplateExpression
{
    public const MAX_LENGTH = 200;

    /** @param array<string, float> $vars */
    public static function evaluate(string $expression, array $vars): float
    {
        $tokens = self::tokenize($expression);
        $pos = 0;
        $value = self::parseSum($tokens, $pos, $vars);
        if ($pos !== count($tokens)) {
            throw new \InvalidArgumentException(sprintf('Expresie invalidă: „%s”.', $expression));
        }
        if (!is_finite($value)) {
            throw new \InvalidArgumentException(sprintf('Expresia „%s” nu are o valoare finită.', $expression));
        }

        return $value;
    }

    /** The names an expression refers to (`curs`, `curs_EUR`, `sold`…), so the caller knows what to resolve. */
    public static function names(string $expression): array
    {
        preg_match_all('/[A-Za-z_][A-Za-z0-9_]*/', $expression, $m);

        return array_values(array_unique($m[0]));
    }

    /** @return list<array{0: string, 1: string}> */
    private static function tokenize(string $expression): array
    {
        if (strlen($expression) > self::MAX_LENGTH) {
            throw new \InvalidArgumentException('Expresia este prea lungă.');
        }
        $tokens = [];
        $len = strlen($expression);
        for ($i = 0; $i < $len;) {
            $c = $expression[$i];
            if (ctype_space($c)) {
                $i++;
                continue;
            }
            if (preg_match('/\G(\d+(?:[.,]\d+)?)/', $expression, $m, 0, $i)) {
                $tokens[] = ['num', str_replace(',', '.', $m[1])];
                $i += strlen($m[1]);
                continue;
            }
            if (preg_match('/\G([A-Za-z_][A-Za-z0-9_]*)/', $expression, $m, 0, $i)) {
                $tokens[] = ['name', $m[1]];
                $i += strlen($m[1]);
                continue;
            }
            if (strpos('+-*/()', $c) !== false) {
                $tokens[] = ['op', $c];
                $i++;
                continue;
            }
            throw new \InvalidArgumentException(sprintf('Caracter neașteptat „%s” în expresia „%s”.', $c, $expression));
        }
        if ($tokens === []) {
            throw new \InvalidArgumentException('Expresia este goală.');
        }

        return $tokens;
    }

    /** @param list<array{0: string, 1: string}> $t @param array<string, float> $vars */
    private static function parseSum(array $t, int &$p, array $vars): float
    {
        $value = self::parseProduct($t, $p, $vars);
        while ($p < count($t) && $t[$p][0] === 'op' && ($t[$p][1] === '+' || $t[$p][1] === '-')) {
            $op = $t[$p++][1];
            $rhs = self::parseProduct($t, $p, $vars);
            $value = $op === '+' ? $value + $rhs : $value - $rhs;
        }

        return $value;
    }

    /** @param list<array{0: string, 1: string}> $t @param array<string, float> $vars */
    private static function parseProduct(array $t, int &$p, array $vars): float
    {
        $value = self::parseUnary($t, $p, $vars);
        while ($p < count($t) && $t[$p][0] === 'op' && ($t[$p][1] === '*' || $t[$p][1] === '/')) {
            $op = $t[$p++][1];
            $rhs = self::parseUnary($t, $p, $vars);
            if ($op === '/') {
                if (abs($rhs) < 1e-12) {
                    throw new \InvalidArgumentException('Împărțire la zero în expresie.');
                }
                $value /= $rhs;
            } else {
                $value *= $rhs;
            }
        }

        return $value;
    }

    /** @param list<array{0: string, 1: string}> $t @param array<string, float> $vars */
    private static function parseUnary(array $t, int &$p, array $vars): float
    {
        if ($p < count($t) && $t[$p][0] === 'op' && ($t[$p][1] === '-' || $t[$p][1] === '+')) {
            $op = $t[$p++][1];
            $v = self::parseUnary($t, $p, $vars);

            return $op === '-' ? -$v : $v;
        }

        return self::parseAtom($t, $p, $vars);
    }

    /** @param list<array{0: string, 1: string}> $t @param array<string, float> $vars */
    private static function parseAtom(array $t, int &$p, array $vars): float
    {
        if ($p >= count($t)) {
            throw new \InvalidArgumentException('Expresia se termină neașteptat.');
        }
        [$type, $text] = $t[$p++];
        if ($type === 'num') {
            return (float) $text;
        }
        if ($type === 'name') {
            if (!array_key_exists($text, $vars)) {
                throw new \InvalidArgumentException(sprintf('Variabilă necunoscută „%s”.', $text));
            }

            return $vars[$text];
        }
        if ($text === '(') {
            $v = self::parseSum($t, $p, $vars);
            if ($p >= count($t) || $t[$p][0] !== 'op' || $t[$p][1] !== ')') {
                throw new \InvalidArgumentException('Paranteză neînchisă în expresie.');
            }
            $p++;

            return $v;
        }
        throw new \InvalidArgumentException(sprintf('Simbol neașteptat „%s” în expresie.', $text));
    }
}
