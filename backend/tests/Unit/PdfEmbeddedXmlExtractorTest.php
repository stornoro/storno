<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\Declaration\PdfEmbeddedXmlExtractor;
use PHPUnit\Framework\TestCase;

/** ANAF PDFs carry the declaration XML as an attachment (DUK) or in the XFA datasets (Acrobat forms). */
final class PdfEmbeddedXmlExtractorTest extends TestCase
{
    private const XML = '<?xml version="1.0" encoding="UTF-8"?><declaratie300 xmlns="mfp:anaf:dgti:d300:declaratie:v7" luna="7" an="2026" cui="12345678"/>';

    private static function pdfWith(string $body): string
    {
        return "%PDF-1.7\n1 0 obj\n<< /Type /Catalog >>\nendobj\n" . $body . "\ntrailer\n<< /Root 1 0 R >>\n%%EOF";
    }

    public function testRawAttachment(): void
    {
        $pdf = self::pdfWith("2 0 obj\n<< /Type /EmbeddedFile /Subtype /text#2Fxml /Length " . strlen(self::XML) . " >>\nstream\n" . self::XML . "\nendstream\nendobj");
        $xml = (new PdfEmbeddedXmlExtractor())->extract($pdf);
        self::assertNotNull($xml);
        self::assertStringContainsString('declaratie300', $xml);
        self::assertStringContainsString('an="2026"', $xml);
    }

    public function testFlateAttachment(): void
    {
        $z = gzcompress(self::XML);
        $pdf = self::pdfWith("2 0 obj\n<< /Type /EmbeddedFile /Filter /FlateDecode /Length " . strlen($z) . " >>\nstream\n" . $z . "\nendstream\nendobj");
        self::assertStringContainsString('luna="7"', (string) (new PdfEmbeddedXmlExtractor())->extract($pdf));
    }

    public function testXfaDatasets(): void
    {
        $datasets = '<xfa:datasets xmlns:xfa="http://www.xfa.org/schema/xfa-data/1.0/"><xfa:data><declaratie112 luna="8" an="2026" cui="1"/></xfa:data></xfa:datasets>';
        $z = gzcompress($datasets);
        $pdf = self::pdfWith("3 0 obj\n<< /Filter /FlateDecode /Length " . strlen($z) . " >>\nstream\n" . $z . "\nendstream\nendobj");
        $xml = (new PdfEmbeddedXmlExtractor())->extract($pdf);
        self::assertNotNull($xml);
        self::assertStringContainsString('<declaratie112', $xml);
        self::assertStringNotContainsString('xfa:data', $xml);
    }

    public function testNothingInsideIsNull(): void
    {
        self::assertNull((new PdfEmbeddedXmlExtractor())->extract(self::pdfWith("2 0 obj\n<< /Length 5 >>\nstream\nhello\nendstream\nendobj")));
        self::assertNull((new PdfEmbeddedXmlExtractor())->extract('not a pdf'));
    }
}
