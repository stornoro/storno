<?php

declare(strict_types=1);

namespace App\Tests\Unit\Import;

use App\Service\Import\Mapper\CashRegisterA4200Mapper;
use App\Service\Import\Parser\A4200XmlParser;
use PHPUnit\Framework\TestCase;

final class A4200XmlParserTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../../Fixtures/import/a4200-day.xml';

    public function testOfficialDayFileBecomesReceiptAndZRows(): void
    {
        $parser = new A4200XmlParser();
        self::assertTrue($parser->supports('a4200_xml'));
        self::assertFalse($parser->supports('saga_xml'));

        $rows = iterator_to_array($parser->parse(self::FIXTURE), false);
        self::assertCount(4, $rows);
        self::assertSame(4, $parser->countRows(self::FIXTURE));

        $first = $rows[0];
        self::assertSame('bon', $first[A4200XmlParser::COL_TYPE]);
        self::assertSame('AB000000012026090710120000120001', $first[A4200XmlParser::COL_FISCAL_ID]);
        self::assertSame('AB00000001', $first[A4200XmlParser::COL_SERIAL], 'the device serial is the first 10 characters of the id');
        self::assertSame('2026-09-07', $first[A4200XmlParser::COL_DATE]);
        self::assertSame('10:12:00', $first[A4200XmlParser::COL_TIME]);
        self::assertSame('12', $first[A4200XmlParser::COL_Z_NUMBER]);
        self::assertSame('1', $first[A4200XmlParser::COL_NUMBER]);
        self::assertSame('121.00', $first[A4200XmlParser::COL_TOTAL]);
        self::assertSame('21.00', $first[A4200XmlParser::COL_VAT_TOTAL]);
        self::assertSame('21:21.00', $first[A4200XmlParser::COL_VAT_BREAKDOWN]);
        self::assertSame('3:121.00', $first[A4200XmlParser::COL_PAYMENTS]);

        $third = $rows[2];
        self::assertSame('21:21.00;11:11.00', $third[A4200XmlParser::COL_VAT_BREAKDOWN]);
        self::assertSame('3:100.00;1:132.00', $third[A4200XmlParser::COL_PAYMENTS]);
        self::assertSame('12345678', $third[A4200XmlParser::COL_CUSTOMER_CIF]);

        $z = $rows[3];
        self::assertSame('Z', $z[A4200XmlParser::COL_TYPE]);
        self::assertSame('408.50', $z[A4200XmlParser::COL_TOTAL]);
        self::assertSame('3', $z[A4200XmlParser::COL_RECEIPT_COUNT]);
        self::assertSame('RON', $z[A4200XmlParser::COL_CURRENCY]);

        $preview = $parser->preview(self::FIXTURE, 2);
        self::assertSame(A4200XmlParser::HEADERS, $preview['headers']);
        self::assertCount(2, $preview['rows']);
        self::assertSame('AB00000001', $preview['metadata']['devices']);

        $mapper = new CashRegisterA4200Mapper();
        self::assertSame(1.0, $mapper->detectConfidence($preview['headers']));
        $mapped = $mapper->mapRow($first, $mapper->getDefaultMapping());
        self::assertSame('bon', $mapped['type']);
        self::assertSame('2026-09-07', $mapped['issueDate']);
        self::assertSame('121.00', $mapped['total']);
    }

    public function testLenientExportWithProductLinesAndZipArchive(): void
    {
        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<jurnal nui="CD00000002" moneda="RON">
  <bon nr="15" data="08.09.2026" ora="11:05:00" total="60.50" tva="10.50">
    <linie den="Cafea" cant="2" pret="12.10" val="24.20" cota="A"/>
    <art den="Sandwich" cant="1" pret="36.30" val="36.30" cota="21"/>
    <plata tip="card" suma="60.50"/>
  </bon>
</jurnal>
XML;
        $dir = sys_get_temp_dir() . '/a4200_' . uniqid();
        mkdir($dir);
        file_put_contents($dir . '/zi-2.xml', $xml);
        $zipPath = $dir . '/luna.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFile(self::FIXTURE, 'zi-1.xml');
        $zip->addFromString('zi-2.xml', $xml);
        $zip->addFromString('opis.xml', '<mReg idM="AB00000001202609302359" cif="12345678" nrRapI="12" nrRapF="13" tip_amef="U"/>');
        $zip->close();

        $parser = new A4200XmlParser();
        $rows = iterator_to_array($parser->parse($zipPath), false);
        self::assertCount(5, $rows, 'four rows of the official day file plus one lenient receipt; the register message adds none');

        $lenient = $rows[4];
        self::assertSame('CD00000002', $lenient[A4200XmlParser::COL_SERIAL]);
        self::assertSame('2026-09-08', $lenient[A4200XmlParser::COL_DATE]);
        self::assertSame('11:05:00', $lenient[A4200XmlParser::COL_TIME]);
        self::assertSame('15', $lenient[A4200XmlParser::COL_NUMBER]);
        self::assertSame('60.50', $lenient[A4200XmlParser::COL_TOTAL]);
        self::assertSame('Cafea|2.00|12.10|24.20|A;Sandwich|1.00|36.30|36.30|21', $lenient[A4200XmlParser::COL_LINES]);
        self::assertSame('card:60.50', $lenient[A4200XmlParser::COL_PAYMENTS]);

        unlink($dir . '/zi-2.xml');
        unlink($zipPath);
        rmdir($dir);
    }

    public function testFileWrittenWithTheOlderAmefFieldNames(): void
    {
        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<mesaj ID_AMEF="EF00000003">
  <bon ID_BON="EF000000032026090914300000130007" DATA_EMITERE_BF="09.09.2026 14:30:00">
    <BENEF cif_beneficiar="87654321"/>
    <CENTRAL total_bon="242.00" total_tva="42.00" total_plata_card="200.00" total_plata_numerar="42.00" total_plata_bv="0.00" total_plata_altele="0.00"/>
    <COTE cota="21" val_cota="42.00"/>
    <ARTICOL den_art="Tricou" cantitate="2" pret="121.00" valoare="242.00" cota="21"/>
  </bon>
</mesaj>
XML;
        $path = sys_get_temp_dir() . '/a4200_older_' . uniqid() . '.xml';
        file_put_contents($path, $xml);

        $rows = iterator_to_array((new A4200XmlParser())->parse($path), false);
        self::assertCount(1, $rows);

        $row = $rows[0];
        self::assertSame('EF000000032026090914300000130007', $row[A4200XmlParser::COL_FISCAL_ID]);
        self::assertSame('EF00000003', $row[A4200XmlParser::COL_SERIAL]);
        self::assertSame('2026-09-09', $row[A4200XmlParser::COL_DATE]);
        self::assertSame('242.00', $row[A4200XmlParser::COL_TOTAL]);
        self::assertSame('42.00', $row[A4200XmlParser::COL_VAT_TOTAL]);
        self::assertSame('21:42.00', $row[A4200XmlParser::COL_VAT_BREAKDOWN]);
        self::assertSame('cash:42.00;card:200.00', $row[A4200XmlParser::COL_PAYMENTS], 'the payment totals of the receipt replace the missing pl elements');
        self::assertSame('87654321', $row[A4200XmlParser::COL_CUSTOMER_CIF]);
        self::assertSame('Tricou|2.00|121.00|242.00|21', $row[A4200XmlParser::COL_LINES]);

        unlink($path);
    }
}
