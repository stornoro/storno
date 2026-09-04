<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\Declaration\Forms\D212Form;
use PHPUnit\Framework\TestCase;

/** D212 rent scenario: forfait, tax, CASS tiers on the minimum wage, summary arithmetic — the numbers ANAF's validator and web form expect. */
final class D212FormTest extends TestCase
{
    public function testRentIncomeAboveSixMinimumWagesGetsCassTierOne(): void
    {
        $form = new D212Form();
        $result = $form->build($form->example());
        self::assertSame([], array_filter($result->issues, fn ($i) => $i['level'] === 'error'), json_encode($result->issues));

        $xml = simplexml_load_string($result->xml);
        self::assertNotFalse($xml);
        $root = $xml->attributes();
        self::assertSame('2026', (string) $root['an_r']);
        self::assertSame('22', (string) $root['totalPlata_A'], 'sum of CNP digits');
        self::assertSame('1', (string) $root['bifa111']);
        self::assertSame('1', (string) $root['bifa132'], 'CASS chapter flagged');

        $cap = $xml->cap11[0]->attributes();
        self::assertSame('1015', (string) $cap['categ_venit']);
        self::assertSame('2', (string) $cap['det_ven_net']);
        self::assertSame('60000', (string) $cap['venit_brut']);
        self::assertSame('12000', (string) $cap['chelt_deduc']);
        self::assertSame('48000', (string) $cap['venit_net_anual']);
        self::assertSame('4800', (string) $cap['impozit11']);

        $s = $xml->oblig_realizat[0]->attributes();
        self::assertSame('4800', (string) $s['oblimpoz_real_total']);
        self::assertSame('1', (string) $s['bifa_cass_real'], '48000 is between 6 and 12 minimum wages of 4050');
        self::assertSame('24300', (string) $s['cass_baza']);
        self::assertSame('2430', (string) $s['cass_datorat']);
        self::assertSame('48000', (string) $s['cass_ven_cfb']);
        self::assertSame('7230', (string) $s['dif_de_plata']);
    }

    public function testBelowThresholdHasNoCassAndTiersFollowTheWage(): void
    {
        $form = new D212Form();
        $input = $form->example();
        $input['chirii'][0]['venitBrut'] = 24000; // net 19200 < 24300
        $xml = simplexml_load_string($form->build($input)->xml);
        self::assertSame('0', (string) $xml->attributes()['bifa132']);
        self::assertNull($xml->oblig_realizat[0]->attributes()['cass_baza'] ?? null);
        self::assertSame('1920', (string) $xml->oblig_realizat[0]->attributes()['dif_de_plata']);

        $input['chirii'][0]['venitBrut'] = 125000; // net 100000 ≥ 24 × 4050
        $s = simplexml_load_string($form->build($input)->xml)->oblig_realizat[0]->attributes();
        self::assertSame('3', (string) $s['bifa_cass_real']);
        self::assertSame('97200', (string) $s['cass_baza']);
        self::assertSame('9720', (string) $s['cass_datorat']);

        $input['chirii'][0]['venitBrut'] = 24000;
        $input['alteVenituriCass'] = ['investitii' => 30000]; // 19200 + 30000 = 49200 ≥ 12 × 4050
        $s = simplexml_load_string($form->build($input)->xml)->oblig_realizat[0]->attributes();
        self::assertSame('2', (string) $s['bifa_cass_real']);
        self::assertSame('30000', (string) $s['cass_ven_inv']);
        self::assertSame('49200', (string) $s['cass_total_ven']);

        $input['an'] = 2025; // income 2024, wage 3300: 49200 ≥ 12 × 3300 = 39600, < 24 × 3300
        $input['chirii'][0]['deLa'] = '01.01.2024';
        $input['chirii'][0]['panaLa'] = '31.12.2024';
        $s = simplexml_load_string($form->build($input)->xml)->oblig_realizat[0]->attributes();
        self::assertSame('39600', (string) $s['cass_baza']);
    }

    public function testRulesRefuseWhatAnafWouldRefuse(): void
    {
        $form = new D212Form();
        $input = $form->example();
        $input['contribuabil']['cnp'] = '1800101400011';
        $input['contribuabil']['adresa'] = 'București, Șos. Ștefan cel Mare 1';
        $input['chirii'][0]['deLa'] = '2024-06-01';
        $input['chirii'][] = ['numarContract' => '5', 'dataContract' => '01.01.2025', 'adresaBun' => 'Birou', 'deLa' => '01.01.2025', 'panaLa' => '31.12.2025', 'venitBrut' => 10000, 'chiriasPersoanaJuridica' => true];
        $result = $form->build($input);
        $codes = array_column($result->issues, 'code');
        self::assertContains('BR-CNP', $codes);
        self::assertContains('D212-PERIOD', $codes);
        self::assertContains('D212-PJ', $codes);
        self::assertContains('STORNO-ASCII', $codes);
        self::assertStringContainsString('adresa_c="Bucuresti, Sos. Stefan cel Mare 1"', $result->xml);
        self::assertSame(1, substr_count($result->xml, '<cap11 '), 'the legal-entity tenant contract is not written');

        $input['an'] = 2024;
        self::assertContains('D212-SCOPE', array_column($form->build($input)->issues, 'code'));
    }
}
