<?php

namespace App\Tests\Unit;

use App\Service\Anaf\ETransportXmlGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ETransportXmlGenerator.
 *
 * Only the methods that accept plain scalar parameters are tested here:
 *   - generateDeletion(string $cif, string $uit)
 *   - generateConfirmation(string $cif, string $uit, int $type, ?string $remarks)
 *
 * generateNotification() and generateCorrection() require a populated
 * DeliveryNote entity and are covered by integration tests.
 *
 * Valid UIT used throughout:
 *   prefix  = "00000000000001" (13 × '0' + '1')
 *   sum     = 13 × 48 + 49 = 673
 *   last 2 digits of 673 = "73"
 *   full UIT = "0000000000000173"
 */
class ETransportXmlGeneratorTest extends TestCase
{
    private ETransportXmlGenerator $generator;

    private const NS       = 'mfp:anaf:dgti:eTransport:declaratie:v2';
    private const VALID_UIT = '0000000000000173';

    protected function setUp(): void
    {
        $this->generator = new ETransportXmlGenerator();
    }

    // -------------------------------------------------------------------------
    // generateDeletion
    // -------------------------------------------------------------------------

    /**
     * The generated deletion XML must include the eTransport namespace, the
     * codDeclarant attribute, and a <stergere> child with the correct uit.
     */
    public function testGenerateDeletion(): void
    {
        $xml = $this->generator->generateDeletion('12345678', self::VALID_UIT);

        $this->assertStringContainsString(self::NS, $xml);
        $this->assertStringContainsString('codDeclarant="12345678"', $xml);
        $this->assertStringContainsString('<stergere', $xml);
        $this->assertStringContainsString('uit="' . self::VALID_UIT . '"', $xml);
    }

    /**
     * When the CIF is prefixed with "RO" the normalizeCif helper must strip
     * the prefix so the output contains only the numeric part.
     */
    public function testGenerateDeletionStripsRoPrefix(): void
    {
        $xml = $this->generator->generateDeletion('RO12345678', self::VALID_UIT);

        $this->assertStringContainsString('codDeclarant="12345678"', $xml);
        $this->assertStringNotContainsString('codDeclarant="RO12345678"', $xml);
    }

    /**
     * The "RO" prefix stripping must be case-insensitive ("ro" should also be stripped).
     */
    public function testGenerateDeletionStripsLowercaseRoPrefix(): void
    {
        $xml = $this->generator->generateDeletion('ro12345678', self::VALID_UIT);

        $this->assertStringContainsString('codDeclarant="12345678"', $xml);
        $this->assertStringNotContainsString('ro12345678', $xml);
    }

    /**
     * The deletion XML must be well-formed and parseable by DOMDocument.
     */
    public function testGenerateDeletionXmlIsWellFormed(): void
    {
        $xml = $this->generator->generateDeletion('12345678', self::VALID_UIT);

        $dom = new \DOMDocument();
        $loaded = @$dom->loadXML($xml);

        $this->assertTrue($loaded, 'DOMDocument::loadXML() failed — generated XML is not well-formed.');
    }

    /**
     * The deletion XML must declare the eTransport namespace on the root element.
     */
    public function testGenerateDeletionHasCorrectNamespace(): void
    {
        $xml = $this->generator->generateDeletion('12345678', self::VALID_UIT);

        $dom = new \DOMDocument();
        $dom->loadXML($xml);

        $root = $dom->documentElement;
        $this->assertSame('eTransport', $root->localName);
        $this->assertSame(self::NS, $root->namespaceURI);
    }

    /**
     * The deletion XML must contain exactly one <stergere> element.
     */
    public function testGenerateDeletionHasOneStergereElement(): void
    {
        $xml = $this->generator->generateDeletion('12345678', self::VALID_UIT);

        $dom = new \DOMDocument();
        $dom->loadXML($xml);

        $stergereElements = $dom->getElementsByTagName('stergere');
        $this->assertSame(1, $stergereElements->length);
    }

    /**
     * A CIF that does not start with "RO" must be passed through unchanged
     * (aside from whitespace trimming).
     */
    public function testGenerateDeletionPreservesNonRoCif(): void
    {
        $xml = $this->generator->generateDeletion('987654321', self::VALID_UIT);

        $this->assertStringContainsString('codDeclarant="987654321"', $xml);
    }

    // -------------------------------------------------------------------------
    // generateConfirmation
    // -------------------------------------------------------------------------

    /**
     * The generated confirmation XML must include the eTransport namespace, the
     * codDeclarant attribute, a <confirmare> child with the correct uit and
     * tipConfirmare, and the optional observatii attribute when remarks are given.
     */
    public function testGenerateConfirmation(): void
    {
        $xml = $this->generator->generateConfirmation(
            '12345678',
            self::VALID_UIT,
            10,
            'Marfa receptata integral',
        );

        $this->assertStringContainsString(self::NS, $xml);
        $this->assertStringContainsString('codDeclarant="12345678"', $xml);
        $this->assertStringContainsString('<confirmare', $xml);
        $this->assertStringContainsString('uit="' . self::VALID_UIT . '"', $xml);
        $this->assertStringContainsString('tipConfirmare="10"', $xml);
        $this->assertStringContainsString('observatii="Marfa receptata integral"', $xml);
    }

    /**
     * When $remarks is null the observatii attribute must be absent from the output.
     */
    public function testGenerateConfirmationWithNullRemarksOmitsObservatii(): void
    {
        $xml = $this->generator->generateConfirmation('12345678', self::VALID_UIT, 10, null);

        $this->assertStringNotContainsString('observatii', $xml);
    }

    /**
     * When $remarks is an empty string the observatii attribute must also be absent.
     */
    public function testGenerateConfirmationWithEmptyRemarksOmitsObservatii(): void
    {
        $xml = $this->generator->generateConfirmation('12345678', self::VALID_UIT, 10, '');

        $this->assertStringNotContainsString('observatii', $xml);
    }

    /**
     * tipConfirmare value 20 (partially confirmed) must appear correctly in the XML.
     */
    public function testGenerateConfirmationWithType20(): void
    {
        $xml = $this->generator->generateConfirmation('12345678', self::VALID_UIT, 20, null);

        $this->assertStringContainsString('tipConfirmare="20"', $xml);
    }

    /**
     * tipConfirmare value 30 (rejected) must appear correctly in the XML.
     */
    public function testGenerateConfirmationWithType30(): void
    {
        $xml = $this->generator->generateConfirmation('12345678', self::VALID_UIT, 30, null);

        $this->assertStringContainsString('tipConfirmare="30"', $xml);
    }

    /**
     * The confirmation XML must be well-formed and parseable by DOMDocument.
     */
    public function testGenerateConfirmationXmlIsWellFormed(): void
    {
        $xml = $this->generator->generateConfirmation('12345678', self::VALID_UIT, 10, null);

        $dom    = new \DOMDocument();
        $loaded = @$dom->loadXML($xml);

        $this->assertTrue($loaded, 'DOMDocument::loadXML() failed — generated XML is not well-formed.');
    }

    /**
     * The confirmation XML must contain exactly one <confirmare> element.
     */
    public function testGenerateConfirmationHasOneConfirmareElement(): void
    {
        $xml = $this->generator->generateConfirmation('12345678', self::VALID_UIT, 10, null);

        $dom = new \DOMDocument();
        $dom->loadXML($xml);

        $elements = $dom->getElementsByTagName('confirmare');
        $this->assertSame(1, $elements->length);
    }

    /**
     * The RO prefix stripping must also apply to the confirmation CIF.
     */
    public function testGenerateConfirmationStripsRoPrefix(): void
    {
        $xml = $this->generator->generateConfirmation('RO12345678', self::VALID_UIT, 10, null);

        $this->assertStringContainsString('codDeclarant="12345678"', $xml);
        $this->assertStringNotContainsString('codDeclarant="RO12345678"', $xml);
    }

    // -------------------------------------------------------------------------
    // XML declaration and encoding
    // -------------------------------------------------------------------------

    /**
     * Both document types must begin with the standard XML declaration for UTF-8.
     */
    public function testDeletionXmlStartsWithXmlDeclaration(): void
    {
        $xml = $this->generator->generateDeletion('12345678', self::VALID_UIT);

        $this->assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $xml);
    }

    public function testConfirmationXmlStartsWithXmlDeclaration(): void
    {
        $xml = $this->generator->generateConfirmation('12345678', self::VALID_UIT, 10, null);

        $this->assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $xml);
    }

    // -------------------------------------------------------------------------
    // Root element structure verified via DOM
    // -------------------------------------------------------------------------

    /**
     * The <confirmare> element must carry the uit and tipConfirmare attributes
     * with the exact values supplied to the method.
     */
    public function testGenerateConfirmationAttributesViaDom(): void
    {
        $xml = $this->generator->generateConfirmation('12345678', self::VALID_UIT, 10, 'Note text');

        $dom = new \DOMDocument();
        $dom->loadXML($xml);

        $confirmareList = $dom->getElementsByTagName('confirmare');
        $this->assertSame(1, $confirmareList->length);

        $confirmare = $confirmareList->item(0);
        $this->assertSame(self::VALID_UIT, $confirmare->getAttribute('uit'));
        $this->assertSame('10', $confirmare->getAttribute('tipConfirmare'));
        $this->assertSame('Note text', $confirmare->getAttribute('observatii'));
    }

    /**
     * The <stergere> element must carry the uit attribute with the exact value
     * supplied to generateDeletion().
     */
    public function testGenerateDeletionUitAttributeViaDom(): void
    {
        $xml = $this->generator->generateDeletion('12345678', self::VALID_UIT);

        $dom = new \DOMDocument();
        $dom->loadXML($xml);

        $stergereList = $dom->getElementsByTagName('stergere');
        $stergere     = $stergereList->item(0);

        $this->assertSame(self::VALID_UIT, $stergere->getAttribute('uit'));
    }
}
