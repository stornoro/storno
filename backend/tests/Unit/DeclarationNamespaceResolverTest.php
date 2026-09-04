<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Model\Declaration\DukValidationResult;
use App\Service\Declaration\DeclarationNamespaceResolver;
use PHPUnit\Framework\TestCase;

final class DeclarationNamespaceResolverTest extends TestCase
{
    private DeclarationNamespaceResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new DeclarationNamespaceResolver(__DIR__ . '/../../resources/declarations');
    }

    public function testAddsDefaultNamespaceToRoot(): void
    {
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<declaratie300 xmlns:xsi=\"http://www.w3.org/2001/XMLSchema-instance\" luna=\"8\" an=\"2026\">\n  <op1 a=\"1\"/>\n</declaratie300>\n";
        $out = $this->resolver->apply($xml, 'mfp:anaf:dgti:d300:declaratie:v12');

        self::assertStringContainsString('<declaratie300 xmlns="mfp:anaf:dgti:d300:declaratie:v12" xmlns:xsi=', $out);
        self::assertSame('mfp:anaf:dgti:d300:declaratie:v12', $this->resolver->fromXml($out));
        self::assertSame('declaratie300', $this->resolver->rootName($out));

        $doc = new \DOMDocument();
        self::assertTrue($doc->loadXML($out));
        self::assertSame('mfp:anaf:dgti:d300:declaratie:v12', $doc->documentElement->namespaceURI);
        self::assertSame('mfp:anaf:dgti:d300:declaratie:v12', $doc->getElementsByTagName('op1')->item(0)->namespaceURI);
    }

    public function testReplacesExistingNamespace(): void
    {
        $xml = '<d212 xmlns="mfp:anaf:dgti:d212:declaratie:v9" an_r="2025"/>';
        $out = $this->resolver->apply($xml, 'mfp:anaf:dgti:d212:declaratie:v11');

        self::assertSame('<d212 xmlns="mfp:anaf:dgti:d212:declaratie:v11" an_r="2025"/>', $out);
    }

    public function testNullNamespaceLeavesXmlAlone(): void
    {
        self::assertSame('<d212/>', $this->resolver->apply('<d212/>', null));
    }

    public function testNamespaceFromShippedXsd(): void
    {
        self::assertSame('mfp:anaf:dgti:d300:declaratie:v12', $this->resolver->fromXsd('d300'));
        self::assertSame('mfp:anaf:dgti:d394:declaratie:v5', $this->resolver->fromXsd('D394'));
        self::assertNull($this->resolver->fromXsd('d999'));
    }

    public function testSuggestedNamespaceFromValidatorError(): void
    {
        $result = new DukValidationResult(false, [
            'F: validari globale',
            "eroare structura: namespace ('mfp:anaf:dgti:d212:declaratie:v9') lipsa sau incorect la sectiunea d212. Valoarea corecta este xmlns='mfp:anaf:dgti:d212:declaratie:v11'",
        ], []);

        self::assertSame('mfp:anaf:dgti:d212:declaratie:v11', $this->resolver->suggestedNamespace($result));
        self::assertNull($this->resolver->suggestedNamespace(new DukValidationResult(false, ['E: cif invalid'], [])));
    }
}
