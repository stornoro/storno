<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\Anaf\AnafFormVersionService;
use PHPUnit\Framework\TestCase;

final class AnafFormVersionServiceTest extends TestCase
{
    public function testManifestParsing(): void
    {
        $xml = <<<XML
<versiuni><integrator><versiune>1.3.15</versiune></integrator><declaratii>
<D300><versiuneJ>J3.2.1</versiuneJ><versiuneP>P2.0.3</versiuneP><JURL>http://x/D300Validator.jar</JURL><PURL>http://x/D300Pdf.jar</PURL><DURL>http://x/D300Istoria.txt</DURL></D300>
<S1001><versiuneJ>J11.0.1</versiuneJ><versiuneP>P2.0.2</versiuneP></S1001>
<documentatie><docURL>http://x/doc.pdf</docURL></documentatie>
</declaratii></versiuni>
XML;
        $m = AnafFormVersionService::parseManifest($xml);
        self::assertSame(['D300', 'S1001'], array_keys($m), 'only form codes, not the documentation block');
        self::assertSame('J3.2.1', $m['D300']['versionJ']);
        self::assertSame('P2.0.3', $m['D300']['versionP']);
        self::assertSame('http://x/D300Istoria.txt', $m['D300']['historyUrl']);
        self::assertNull($m['S1001']['pdfUrl']);
        self::assertContains('D300', AnafFormVersionService::STORNO_FORMS);
    }

    public function testBrokenManifestIsAnError(): void
    {
        $this->expectException(\RuntimeException::class);
        AnafFormVersionService::parseManifest('<html>maintenance</html>');
    }
}
