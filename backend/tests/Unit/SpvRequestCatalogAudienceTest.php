<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\Spv\SpvRequestCatalog;
use PHPUnit\Framework\TestCase;

/** The SPV form offers a CNP (13 digits) a different type list than a CUI and applies its own period rules. */
final class SpvRequestCatalogAudienceTest extends TestCase
{
    private const CNP = '1800101400016';
    private const CUI = '12345678';

    public function testListsFollowTheIdentifier(): void
    {
        $c = new SpvRequestCatalog();
        $forPerson = array_column($c->types(self::CNP), 'type');
        $forCompany = array_column($c->types(self::CUI), 'type');

        self::assertContains('D212', $forPerson);
        self::assertContains('Adeverinte Venit', $forPerson);
        self::assertContains('Istoric declaratii PF', $forPerson);
        self::assertContains('C168', $forPerson, 'landlords register contracts as persons');
        self::assertContains('VECTOR FISCAL', $forPerson);
        self::assertNotContains('D300', $forPerson);
        self::assertNotContains('Bilant anual', $forPerson);
        self::assertNotContains('Decizie anulare TVA', $forPerson);

        self::assertContains('D300', $forCompany);
        self::assertContains('Istoric declaratii', $forCompany);
        self::assertNotContains('D212', $forCompany);
        self::assertNotContains('Adeverinte Venit', $forCompany);
        self::assertSame('all', 'all');
        self::assertCount(count($c->types()), array_unique(array_column($c->types(), 'type')));
    }

    public function testRequestsOutsideTheListAreRefused(): void
    {
        $c = new SpvRequestCatalog();
        try {
            $c->buildRequest('D300', self::CNP, ['an' => '2026', 'luna' => '7']);
            self::fail('D300 is not offered to a person');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('persoană fizică', $e->getMessage());
        }
        try {
            $c->buildRequest('D212', self::CUI, ['an' => '2026']);
            self::fail('D212 is not offered to a company');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('doar pentru o persoană fizică', $e->getMessage());
        }
    }

    public function testWebFormPeriodRules(): void
    {
        $c = new SpvRequestCatalog();
        $r = $c->buildRequest('C168', self::CNP, ['an' => '2026']);
        self::assertSame('web', $r['channel']);
        self::assertArrayNotHasKey('an', $r['form']['params'], 'the form takes C168 without a period');

        $r = $c->buildRequest('Duplicat declaratie unica', self::CNP, ['an' => '2025']);
        self::assertSame(['an' => '2025', 'luna' => '12'], $r['form']['params'], 'annual types get month 12 like the form does');

        $r = $c->buildRequest('VECTOR FISCAL', self::CNP, []);
        self::assertSame('ws', $r['channel']);
        self::assertStringContainsString('cui=' . self::CNP, $r['url']);
    }
}
