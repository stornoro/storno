<?php

namespace App\Tests\Unit;

use App\MessageHandler\Declaration\CheckDeclarationStatusHandler;
use PHPUnit\Framework\TestCase;

/**
 * ANAF's portal answers "Documentul este valid" as soon as it can read the file. Whether the
 * declaration entered its records is written in the recipisa, so that is what decides the status.
 */
final class RecipisaErrorsTest extends TestCase
{
    private function pdf(string $text): string
    {
        // a minimal one-page PDF carrying the text, enough for the parser
        $stream = "BT /F1 10 Tf 40 750 Td (" . str_replace(['(', ')'], ['\\(', '\\)'], $text) . ") Tj ET";
        $objects = [
            "1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj",
            "2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj",
            "3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]/Resources<</Font<</F1 5 0 R>>>>/Contents 4 0 R>>endobj",
            "4 0 obj<</Length " . strlen($stream) . ">>stream\n" . $stream . "\nendstream endobj",
            "5 0 obj<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>endobj",
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $object) {
            $offsets[] = strlen($pdf);
            $pdf .= $object . "\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= "trailer<</Size " . (count($objects) + 1) . "/Root 1 0 R>>\nstartxref\n" . $xref . "\n%%EOF";

        return $pdf;
    }

    public function testARecipisaListingErrorsIsReported(): void
    {
        $pdf = $this->pdf('Au fost identificate urmatoarele ERORI: E: validari globale eroare regula: R_NO_INIT_DEC: Nu a fost depusa o declaratie initiala valida pentru aceeasi perioada');

        $errors = CheckDeclarationStatusHandler::errorsInRecipisa($pdf);

        self::assertNotNull($errors);
        self::assertStringContainsString('R_NO_INIT_DEC', $errors);
    }

    public function testAnAcceptedRecipisaHasNoErrors(): void
    {
        self::assertNull(CheckDeclarationStatusHandler::errorsInRecipisa(
            $this->pdf('Ati depus o declaratie tip D212. Nu exista erori de validare.'),
        ));
    }

    public function testSomethingThatIsNotAPdfIsNotTreatedAsAnError(): void
    {
        self::assertNull(CheckDeclarationStatusHandler::errorsInRecipisa('not a pdf at all'));
    }

    public function testTheLegacyDiacriticsOfTheRecipisaDoNotFakeAnError(): void
    {
        // the parser renders "există" with the diacritics of a legacy encoding
        self::assertNull(CheckDeclarationStatusHandler::errorsInRecipisa(
            $this->pdf('Ati depus o declaratie tip D212 cu numarul INTERNT-100000123-2026. Nu existã erori de validare.'),
        ));
    }

    public function testAnAttributeErrorIsReported(): void
    {
        $errors = CheckDeclarationStatusHandler::errorsInRecipisa(
            $this->pdf('Au fost identificate urmãtoarele ERORI: E: validari globale eroare atribut: bifa19: atributul trebuie sa existe'),
        );

        self::assertNotNull($errors);
        self::assertStringContainsString('bifa19', $errors);
    }
}
