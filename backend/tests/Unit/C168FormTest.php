<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\Declaration\Forms\C168Form;
use PHPUnit\Framework\TestCase;

/** The C168 builder writes ANAF's v3 XML from the plain input and applies the rules ANAF's web form enforces. */
final class C168FormTest extends TestCase
{
    public function testExampleBuildsATerminationWithTheDesignatedLandlordAsSoleOwner(): void
    {
        $form = new C168Form();
        $result = $form->build($form->example());

        self::assertSame([], array_filter($result->issues, fn ($i) => $i['level'] === 'error'), json_encode($result->issues));
        $xml = simplexml_load_string($result->xml);
        self::assertNotFalse($xml);
        self::assertSame(C168Form::NAMESPACE, $xml->getNamespaces()[''] ?? null);
        $root = $xml->attributes();
        self::assertSame('2026', (string) $root['an']);
        self::assertSame('12', (string) $root['luna']);
        self::assertSame('0', (string) $root['d_rec']);
        self::assertSame('1', (string) $root['RS_L']);
        self::assertSame('40', (string) $root['judet_L']);
        self::assertSame('412', (string) $root['cod_strada_L']);

        $contract = $xml->contract[0]->attributes();
        self::assertSame('1', (string) $contract['ID_contract']);
        self::assertSame('1', (string) $contract['bifa_incet']);
        self::assertSame('0', (string) $contract['bifa_modif']);
        self::assertSame('1', (string) $contract['bifa_bun']);
        self::assertSame('100', (string) $contract['proc_n4']);
        self::assertSame('01.12.2024', (string) $contract['per1_S']);
        self::assertSame('01.12.2024', (string) $contract['per2_S'], 'per2_S defaults to per1_S');
        self::assertSame('061072', (string) $contract['codp_C']);
        self::assertSame('450', (string) $contract['chirie1']);

        self::assertCount(1, $xml->contract[0]->locatar);
        self::assertCount(1, $xml->contract[0]->locator, 'the designated landlord is added as the only owner');
        $owner = $xml->contract[0]->locator[0]->attributes();
        self::assertSame('1', (string) $owner['d_decl']);
        self::assertSame('100', (string) $owner['proc_n4P']);
        self::assertSame('1800101400016', (string) $owner['cif_P']);
    }

    public function testWebFormRulesAreReportedBeforeAnafSeesTheFile(): void
    {
        $form = new C168Form();
        $input = $form->example();
        $input['contracte'][0]['deLa'] = '2023-12-01';
        unset($input['contracte'][0]['bun']['adresa']['codPostal']);
        unset($input['contracte'][0]['locatari'][0]['cif']);
        $input['contracte'][0]['locatori'] = [
            ['desemnat' => true, 'denumire' => 'POPESCU ION', 'cif' => '1800101400016', 'cotaVenit' => 50, 'adresa' => $input['locator']['adresa']],
            ['denumire' => 'POPESCU ANA', 'cif' => '2800101400018', 'cotaVenit' => 30, 'adresa' => $input['locator']['adresa']],
        ];
        $result = $form->build($input);
        $codes = array_column($result->issues, 'code');

        self::assertContains('BR-C168-0041', $codes, 'postal code of the property');
        self::assertContains('BR-C168-00991', $codes, 'quotas 50 + 30 != 100');
        self::assertContains('BR-C168-005911', $codes, 'tenant CNP missing with a Romanian address');
        $cnp = array_values(array_filter($result->issues, fn ($i) => $i['code'] === 'BR-C168-005911'))[0];
        self::assertSame('error', $cnp['level'], 'ANAF rejects the request in processing without the tenant CNP (G000)');
        self::assertTrue($result->hasErrors());
        self::assertStringContainsString('per1_C="01.12.2023"', $result->xml, 'ISO dates are converted to DD.MM.YYYY');
        self::assertStringNotContainsString('cif_Ch=', $result->xml, 'no placeholder identifiers are ever written');
    }

    public function testSpecDescribesEveryXsdAttributeAndTheRules(): void
    {
        $spec = (new C168Form())->spec();
        self::assertSame('C168', $spec['type']);
        self::assertArrayHasKey('contract', $spec['xml']['attributes']);
        $names = array_column($spec['xml']['attributes']['contract'], 'name');
        self::assertContains('proc_n4', $names);
        self::assertContains('BR-C168-00991', array_column($spec['rules'], 'code'));
        self::assertArrayHasKey('contracte', $spec['input']);
    }

    public function testAnafIdentifierRulesAreApplied(): void
    {
        $form = new C168Form();
        $input = $form->example();
        $input['locator']['cif'] = '1800101400011';
        $input['locator']['organFiscal'] = '408006';
        $input['contracte'][0]['locatori'] = [['desemnat' => true, 'denumire' => 'POPESCU ION', 'cif' => '1800101400011', 'fractie' => ['numarator' => 1, 'numitor' => 2], 'cotaBun' => 50, 'adresa' => $input['locator']['adresa']]];
        $result = $form->build($input);
        $codes = array_column($result->issues, 'code');
        self::assertContains('BR-CNP-0002', $codes, 'wrong control digit');
        self::assertContains('BR-C168-0031', $codes, 'organ fiscal only for NIF');
        self::assertContains('DUK-FRACTIE', $codes);
        self::assertStringNotContainsString('ufisc_L=', $result->xml);
        self::assertStringContainsString('fractie_n1P="1"', $result->xml);
        self::assertStringNotContainsString('proc_n3P=', $result->xml, 'R78: fraction wins, share omitted');
    }
}
