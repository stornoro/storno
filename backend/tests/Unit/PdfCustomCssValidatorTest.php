<?php

namespace App\Tests\Unit;

use App\Service\DocumentPdfService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PdfCustomCssValidatorTest extends TestCase
{
    public static function validCss(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'simple rule' => ['.header { color: #ff0000; font-weight: bold; }'];
        yield 'child combinator' => ['table > tr > td { padding: 2px; }'];
        yield 'comment' => ['/* brand */ .totals { border-top: 1px solid #000; }'];
        yield 'media query' => ['@media print { .footer { display: none; } }'];
        yield 'word url without paren' => ['.url-label { color: red; }'];
        yield 'max length' => [str_repeat('a', DocumentPdfService::CUSTOM_CSS_MAX_LENGTH)];
    }

    #[DataProvider('validCss')]
    public function testValidCssIsAccepted(?string $css): void
    {
        self::assertNull(DocumentPdfService::validateCustomCss($css));
    }

    public static function invalidCss(): iterable
    {
        yield 'style breakout' => ['</style><script>alert(1)</script>', 'Custom CSS must not contain the "<" character.'];
        yield 'lone lt' => ['a < b', 'Custom CSS must not contain the "<" character.'];
        yield 'import' => ['@import url("http://evil/x.css");', 'Custom CSS must not contain @import rules.'];
        yield 'import with spaces' => ['@ import "x.css";', 'Custom CSS must not contain @import rules.'];
        yield 'url' => ['body { background: url(file:///etc/passwd); }', 'Custom CSS must not contain url() references.'];
        yield 'url uppercase and spaced' => ['body { background: URL ( "x" ); }', 'Custom CSS must not contain url() references.'];
        yield 'url split by comment' => ['body { background: url/**/(x); }', 'Custom CSS must not contain url() references.'];
        yield 'expression' => ['width: expression(alert(1));', 'Custom CSS must not contain expression().'];
        yield 'javascript scheme' => ['content: "javascript:alert(1)";', 'Custom CSS must not contain "javascript:" URLs.'];
        yield 'hex escape of lt' => ['content: "\\3c/style>";', 'Custom CSS must not contain backslash escape sequences.'];
        yield 'escaped url' => ['background: \\75rl(x);', 'Custom CSS must not contain backslash escape sequences.'];
        yield 'moz-binding' => ['-moz-binding: x;', 'Custom CSS must not contain -moz-binding.'];
        yield 'behavior' => ['behavior: x;', 'Custom CSS must not contain "behavior:" declarations.'];
        yield 'null byte' => ["a {}\0", 'Custom CSS contains control characters, which are not allowed.'];
        yield 'too long' => [str_repeat('a', DocumentPdfService::CUSTOM_CSS_MAX_LENGTH + 1), 'Custom CSS is too long (maximum 20000 characters).'];
    }

    #[DataProvider('invalidCss')]
    public function testInvalidCssIsRejectedWithMessage(string $css, string $expectedMessage): void
    {
        self::assertSame($expectedMessage, DocumentPdfService::validateCustomCss($css));
    }

    public static function fontFamilies(): iterable
    {
        yield 'null' => [null, true];
        yield 'empty' => ['', true];
        yield 'simple' => ['DejaVu Sans', true];
        yield 'quoted list' => ["'Noto Sans KR', Arial, sans-serif", true];
        yield 'hyphen' => ['Open-Sans', true];
        yield 'style breakout' => ["'; } </style><script>alert(1)</script>", false];
        yield 'semicolon' => ['Arial; color: red', false];
        yield 'parenthesis' => ['url(x)', false];
        yield 'too long' => [str_repeat('a', 101), false];
    }

    #[DataProvider('fontFamilies')]
    public function testFontFamilyValidation(?string $font, bool $valid): void
    {
        $result = DocumentPdfService::validateFontFamily($font);
        if ($valid) {
            self::assertNull($result);
        } else {
            self::assertSame(
                'Invalid font family. Use only letters, digits, spaces, commas, quotes and hyphens (max 100 characters).',
                $result
            );
        }
    }
}
