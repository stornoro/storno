<?php

namespace App\Tests\Unit;

use App\Service\Anaf\ETransportSchematronValidator;
use App\Service\Anaf\ETransportValidator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for ETransportValidator.
 *
 * validateEntity() is not covered here because it requires a populated
 * DeliveryNote entity with Doctrine collections — that belongs to integration
 * tests. These tests target validateXml() and the UIT checksum logic that is
 * exercised indirectly through well-crafted XML strings and known-valid / known-
 * invalid UIT values passed to the XSD pattern constraint.
 *
 * Valid UIT derivation used throughout these tests:
 *   prefix  = "00000000000001"  (13 × '0' + '1', 14 chars total)
 *   sum     = 13 × ord('0') + ord('1') = 13×48 + 49 = 673
 *   last 2 digits of 673 = "73"
 *   valid UIT = "0000000000000173"
 */
class ETransportValidatorTest extends TestCase
{
    private ETransportValidator $validator;

    /** Absolute path to the backend project root. */
    private string $projectDir;

    /** A 16-character UIT that satisfies the checksum algorithm (BR-019). */
    private const VALID_UIT = '0000000000000173';

    /** A 16-character UIT with a wrong check suffix (expected "73", given "99"). */
    private const UIT_BAD_CHECKSUM = '0000000000000199';

    /** A 15-character UIT (one char short). */
    private const UIT_TOO_SHORT = '000000000000017';

    /** A 16-character UIT whose prefix contains "O" — a forbidden character. */
    private const UIT_BAD_CHARSET = 'OOOOOOOOOOOOOO73';

    protected function setUp(): void
    {
        $this->projectDir = dirname(__DIR__, 2);
        $schematronValidator = new ETransportSchematronValidator($this->projectDir, 'java', new NullLogger());
        $this->validator = new ETransportValidator($this->projectDir, $schematronValidator, new NullLogger());
    }

    // -------------------------------------------------------------------------
    // validateXml — well-formed / XSD-valid XML
    // -------------------------------------------------------------------------

    /**
     * A minimal stergere XML that satisfies the XSD must produce zero errors.
     *
     * stergere is the simplest document type: just a root eTransport element
     * with codDeclarant and a <stergere uit="..."/> child.  The UIT used here
     * is VALID_UIT which also satisfies the XSD UitType pattern.
     */
    public function testValidXmlPasses(): void
    {
        $xml = $this->buildStergereXml('12345678', self::VALID_UIT);

        $errors = $this->validator->validateXml($xml);

        $this->assertSame([], $errors);
    }

    /**
     * A minimal confirmare XML with tipConfirmare=10 must also pass XSD validation.
     */
    public function testValidConfirmareXmlPasses(): void
    {
        $xml = $this->buildConfirmareXml('12345678', self::VALID_UIT, 10);

        $errors = $this->validator->validateXml($xml);

        $this->assertSame([], $errors);
    }

    // -------------------------------------------------------------------------
    // validateXml — malformed / structurally invalid XML
    // -------------------------------------------------------------------------

    /**
     * Broken XML (unclosed tag) must produce at least one error with rule "XSD".
     */
    public function testMalformedXmlFails(): void
    {
        $errors = $this->validator->validateXml('<unclosed>');

        $this->assertNotEmpty($errors);
        $this->assertSame('XSD', $errors[0]['rule']);
        $this->assertSame('fatal', $errors[0]['severity']);
    }

    /**
     * Completely empty string is not valid XML.
     */
    public function testEmptyStringFails(): void
    {
        $errors = $this->validator->validateXml('');

        $this->assertNotEmpty($errors);
        $this->assertSame('XSD', $errors[0]['rule']);
    }

    /**
     * A well-formed XML document that does NOT conform to the XSD schema
     * (missing required codDeclarant attribute) must return XSD errors.
     */
    public function testWellFormedButInvalidXmlFailsXsd(): void
    {
        $ns  = 'mfp:anaf:dgti:eTransport:declaratie:v2';
        // codDeclarant is required per XSD but intentionally omitted here
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<eTransport xmlns="' . $ns . '">'
            . '<stergere uit="' . self::VALID_UIT . '"/>'
            . '</eTransport>';

        $errors = $this->validator->validateXml($xml);

        $this->assertNotEmpty($errors);
        $this->assertSame('XSD', $errors[0]['rule']);
    }

    /**
     * An eTransport document whose stergere has a UIT that violates the
     * XSD UitType pattern (wrong length) must produce XSD errors.
     */
    public function testXsdRejectsShortUit(): void
    {
        $xml = $this->buildStergereXml('12345678', self::UIT_TOO_SHORT);

        $errors = $this->validator->validateXml($xml);

        $this->assertNotEmpty($errors);
        $this->assertSame('XSD', $errors[0]['rule']);
    }

    /**
     * A UIT whose prefix contains a character outside the allowed set
     * (the XSD pattern [0-9ACDEFHJKLMNPQRTUVWXY]{14}[0-9]{2}) must be rejected.
     */
    public function testXsdRejectsBadCharsetUit(): void
    {
        $xml = $this->buildStergereXml('12345678', self::UIT_BAD_CHARSET);

        $errors = $this->validator->validateXml($xml);

        $this->assertNotEmpty($errors);
        $this->assertSame('XSD', $errors[0]['rule']);
    }

    /**
     * A codDeclarant that does not match the CodDeclarantType pattern
     * (2-10 or exactly 13 digits) must fail XSD validation.
     */
    public function testXsdRejectsInvalidCodDeclarant(): void
    {
        $xml = $this->buildStergereXml('INVALID_CIF', self::VALID_UIT);

        $errors = $this->validator->validateXml($xml);

        $this->assertNotEmpty($errors);
        $this->assertSame('XSD', $errors[0]['rule']);
    }

    /**
     * A confirmare with an unknown tipConfirmare value (99) must fail XSD.
     */
    public function testXsdRejectsUnknownTipConfirmare(): void
    {
        $xml = $this->buildConfirmareXml('12345678', self::VALID_UIT, 99);

        $errors = $this->validator->validateXml($xml);

        $this->assertNotEmpty($errors);
        $this->assertSame('XSD', $errors[0]['rule']);
    }

    // -------------------------------------------------------------------------
    // validateXml — missing XSD file
    // -------------------------------------------------------------------------

    /**
     * When the validator is constructed with a non-existent project directory
     * the XSD file will not be found; a single XSD error must be returned.
     */
    public function testMissingXsdFileReturnsError(): void
    {
        $badSchematron = new ETransportSchematronValidator('/nonexistent/path', 'java', new NullLogger());
        $validatorWithBadPath = new ETransportValidator('/nonexistent/path/that/does/not/exist', $badSchematron, new NullLogger());

        // We need a well-formed XML so the DOMDocument::loadXML() step passes
        $xml = $this->buildStergereXml('12345678', self::VALID_UIT);

        $errors = $validatorWithBadPath->validateXml($xml);

        $this->assertCount(1, $errors);
        $this->assertSame('XSD', $errors[0]['rule']);
        $this->assertSame('fatal', $errors[0]['severity']);
        $this->assertStringContainsString('schema_ETR_v2.xsd', $errors[0]['message']);
    }

    // -------------------------------------------------------------------------
    // validateXml — error structure contract
    // -------------------------------------------------------------------------

    /**
     * Every error entry returned by validateXml() must contain the three
     * mandatory keys: rule, message, severity.
     */
    public function testErrorStructureContainsRequiredKeys(): void
    {
        $errors = $this->validator->validateXml('<bad>');

        $this->assertNotEmpty($errors);
        foreach ($errors as $error) {
            $this->assertArrayHasKey('rule', $error);
            $this->assertArrayHasKey('message', $error);
            $this->assertArrayHasKey('severity', $error);
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Builds a minimal but schema-valid stergere XML document.
     *
     * The stergere element type (CorectieType) only requires the uit attribute,
     * and the eTransport root only requires codDeclarant and one child element.
     */
    private function buildStergereXml(string $codDeclarant, string $uit): string
    {
        $ns = 'mfp:anaf:dgti:eTransport:declaratie:v2';

        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<eTransport xmlns="' . $ns . '" codDeclarant="' . $codDeclarant . '">'
            . '<stergere uit="' . $uit . '"/>'
            . '</eTransport>';
    }

    /**
     * Builds a minimal confirmare XML document.
     *
     * Valid tipConfirmare values: 10 (confirmed), 20 (partially confirmed), 30 (rejected).
     */
    private function buildConfirmareXml(string $codDeclarant, string $uit, int $tipConfirmare): string
    {
        $ns = 'mfp:anaf:dgti:eTransport:declaratie:v2';

        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<eTransport xmlns="' . $ns . '" codDeclarant="' . $codDeclarant . '">'
            . '<confirmare uit="' . $uit . '" tipConfirmare="' . $tipConfirmare . '"/>'
            . '</eTransport>';
    }
}
