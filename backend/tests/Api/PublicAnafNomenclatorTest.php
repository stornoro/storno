<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\AnafNomenclatorEntry;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Local nomenclator lookups (seeded rows, no network): search is diacritic-insensitive and word-prefixed. */
final class PublicAnafNomenclatorTest extends WebTestCase
{
    public function testStreetsAreServedFromTheLocalMirror(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get('doctrine')->getManager();
        $repo = $em->getRepository(AnafNomenclatorEntry::class);
        foreach ([['judet', '', '40', 'MUNICIPIUL BUCUREŞTI'], ['localitate', '40', '6', '6 Sector - Mun. Bucureşti'], ['strada', '40-6', '59', 'Str. Azurului'], ['strada', '40-6', '412', 'Bld. Iuliu Maniu'], ['strada', '40-6', '9', 'Aleea Ştefăneşti']] as [$kind, $parent, $code, $name]) {
            if ($repo->findOneBy(['kind' => $kind, 'parentKey' => $parent, 'code' => $code]) === null) {
                $em->persist((new AnafNomenclatorEntry())->setKind($kind)->setParentKey($parent)->setCode($code)->setName($name));
            }
        }
        $em->flush();

        $client->request('GET', '/api/v1/public/anaf/nomenclator/strazi/40/6?q=maniu');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $data = json_decode((string) $client->getResponse()->getContent(), true)['data'];
        self::assertSame('412', $data[0]['code']);
        self::assertSame('Bld. Iuliu Maniu', $data[0]['name']);

        $client->request('GET', '/api/v1/public/anaf/nomenclator/strazi/40/6?q=stefanesti');
        $data = json_decode((string) $client->getResponse()->getContent(), true)['data'];
        self::assertSame('9', $data[0]['code'], 'diacritics must not matter');

        self::assertSame('bld. iuliu maniu', AnafNomenclatorEntry::normalize('Bld.  Iuliu  Maniu'));
        self::assertSame('soseaua stefan cel mare', AnafNomenclatorEntry::normalize('Şoseaua Ştefan cel Mare'));
    }
}
