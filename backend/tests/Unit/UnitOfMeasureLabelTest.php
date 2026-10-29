<?php

namespace App\Tests\Unit;

use App\Twig\PdfLabelExtension;
use App\Util\UnitOfMeasure;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

/** Units on a PDF follow the document's language, whether the line carries a label or an e-Factura code. */
class UnitOfMeasureLabelTest extends TestCase
{
    private PdfLabelExtension $ext;

    protected function setUp(): void
    {
        $translator = new Translator('ro');
        $translator->addLoader('yaml', new YamlFileLoader());
        foreach (['ro', 'en', 'de', 'fr'] as $l) {
            $translator->addResource('yaml', __DIR__ . "/../../translations/pdf.$l.yaml", $l, 'pdf');
        }
        $this->ext = new PdfLabelExtension($translator);
    }

    /** @dataProvider units */
    public function testLabels(string $raw, string $locale, string $expected): void
    {
        self::assertSame($expected, $this->ext->unitLabel($raw, $locale));
    }

    public static function units(): iterable
    {
        yield ['buc', 'ro', 'buc'];
        yield ['buc', 'en', 'pcs'];
        yield ['buc', 'de', 'Stk.'];
        yield ['H87', 'ro', 'buc'];
        yield ['C62', 'en', 'pcs'];
        yield ['EA', 'fr', 'pcs'];
        yield ['luna', 'en', 'month'];
        yield ['Luna - MON', 'en', 'month'];
        yield ['MON', 'ro', 'luna'];
        yield ['ORA', 'en', 'hour'];
        yield ['HUR', 'de', 'Std.'];
        yield ['zi', 'fr', 'jour'];
        yield ['KWH', 'ro', 'kWh'];
        yield ['LTR', 'en', 'l'];
        yield ['mp', 'en', 'm²'];
        yield ['serv', 'en', 'service'];
        yield ['XYZ', 'en', 'XYZ']; // unknown: printed as is
        yield ['', 'en', ''];
    }

    public function testEveryTranslatedKeyExistsInEveryLanguage(): void
    {
        foreach (UnitOfMeasure::TRANSLATED as $key) {
            foreach (['ro', 'en', 'de', 'fr'] as $l) {
                self::assertNotSame('units.' . $key, $this->ext->unitLabel($key, $l), "$key missing in $l");
            }
        }
    }
}
