<?php

namespace App\Tests\Unit\Import;

use App\Service\Import\Mapper\GenericInvoiceMapper;
use App\Service\Import\Mapper\PartnerHeaderAliases;
use App\Service\Import\Parser\SagaXmlParser;
use PHPUnit\Framework\TestCase;

class SagaXmlInvoiceImportTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = tempnam(sys_get_temp_dir(), 'facturi_') . '.xml';
        file_put_contents($this->file, <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Facturi>
  <Factura>
    <Antet>
      <FurnizorNume>Firma Exemplu SRL</FurnizorNume>
      <FurnizorCIF>RO11223344</FurnizorCIF>
      <ClientNume>Client Exemplu SRL</ClientNume>
      <ClientCIF>12345678</ClientCIF>
      <ClientLocalitate>Cluj-Napoca</ClientLocalitate>
      <ClientMail>contact@client-exemplu.ro</ClientMail>
      <FacturaNumar>FCT0125</FacturaNumar>
      <FacturaData>15.10.2026</FacturaData>
      <FacturaScadenta>30.10.2026</FacturaScadenta>
      <FacturaMoneda>RON</FacturaMoneda>
    </Antet>
    <Detalii>
      <Continut>
        <Linie>
          <LinieNrCrt>1</LinieNrCrt>
          <Descriere>Servicii consultanta</Descriere>
          <UM>ora</UM>
          <Cantitate>10.000</Cantitate>
          <Pret>100.0000</Pret>
          <Valoare>1000.00</Valoare>
          <ProcTVA>21</ProcTVA>
          <TVA>210.00</TVA>
        </Linie>
        <Linie>
          <LinieNrCrt>2</LinieNrCrt>
          <Descriere>Transport</Descriere>
          <UM>buc</UM>
          <Cantitate>1.000</Cantitate>
          <Pret>50.0000</Pret>
          <Valoare>50.00</Valoare>
          <CotaTVA>21</CotaTVA>
          <TVA>10.50</TVA>
        </Linie>
      </Continut>
    </Detalii>
    <Observatii><txtObservatii>Plata in 15 zile</txtObservatii></Observatii>
    <FacturaID>125</FacturaID>
  </Factura>
  <Factura>
    <Antet>
      <FurnizorCIF>RO11223344</FurnizorCIF>
      <ClientNume>Popescu Ion</ClientNume>
      <FacturaNumar>FCT0126</FacturaNumar>
      <FacturaData>16.10.2026</FacturaData>
    </Antet>
  </Factura>
</Facturi>
XML);
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    public function testInvoiceFileGivesOneRowPerLine(): void
    {
        $parser = new SagaXmlParser();
        $rows = iterator_to_array($parser->parse($this->file), false);

        $this->assertCount(3, $rows);
        $this->assertSame(3, $parser->countRows($this->file));
        $this->assertSame('FCT0125', $rows[0]['FacturaNumar']);
        $this->assertSame('Servicii consultanta', $rows[0]['Descriere']);
        $this->assertSame('Transport', $rows[1]['Descriere']);
        $this->assertSame('FCT0125', $rows[1]['FacturaNumar']);
        $this->assertSame('Plata in 15 zile', $rows[1]['txtObservatii']);
        $this->assertSame('FCT0126', $rows[2]['FacturaNumar']);
        $this->assertArrayNotHasKey('Descriere', $rows[2]);

        $preview = $parser->preview($this->file);
        $this->assertContains('ClientCIF', $preview['headers']);
        $this->assertContains('ProcTVA', $preview['headers']);
        $this->assertContains('CotaTVA', $preview['headers']);
    }

    public function testInvoiceMapperReadsTheXmlFields(): void
    {
        $parser = new SagaXmlParser();
        $mapper = new GenericInvoiceMapper('smartbill');
        $mapping = $mapper->suggestMapping($parser->preview($this->file)['headers']);

        $this->assertSame('number', $mapping['FacturaNumar']);
        $this->assertSame('receiverName', $mapping['ClientNume']);
        $this->assertSame('receiverCif', $mapping['ClientCIF']);
        $this->assertSame('senderCif', $mapping['FurnizorCIF']);
        $this->assertArrayNotHasKey('FurnizorNume', $mapping);
        $this->assertSame('lineTotal', $mapping['Valoare']);
        $this->assertSame('lineVatAmount', $mapping['TVA']);
        $this->assertSame('lineVatRate', $mapping['ProcTVA']);
        $this->assertSame(0.9, $mapper->detectConfidence(array_keys($mapping)));

        $rows = iterator_to_array($parser->parse($this->file), false);
        $first = $mapper->mapRow($rows[0], $mapping);
        $this->assertSame('2026-10-15', $first['issueDate']);
        $this->assertSame('2026-10-30', $first['dueDate']);
        $this->assertSame('RO11223344', $first['senderCif']);
        $this->assertSame(['1000.00', '210.00', '1210.00'], [$first['subtotal'], $first['vatTotal'], $first['total']]);
        $this->assertSame('10', $first['lines'][0]['quantity']);
        $this->assertSame('Cluj-Napoca', $first['clientCity']);
        $this->assertSame('Plata in 15 zile', $first['notes']);

        // the second line names its rate CotaTVA: the mapping has one rate column, the amounts still add up
        $second = $mapper->mapRow($rows[1], $mapping);
        $this->assertSame(['50.00', '10.50', '60.50'], [$second['subtotal'], $second['vatTotal'], $second['total']]);
        $this->assertSame('21.00', $second['lines'][0]['vatRate']);
    }

    public function testReceivedDirectionSwapsTheParties(): void
    {
        $mapper = new GenericInvoiceMapper('smartbill', GenericInvoiceMapper::TYPE_RECEIVED);
        $mapping = $mapper->suggestMapping(['FurnizorNume', 'FurnizorCIF', 'ClientNume', 'ClientCIF', 'FacturaNumar']);

        $this->assertSame('senderName', $mapping['FurnizorNume']);
        $this->assertSame('senderCif', $mapping['FurnizorCIF']);
        $this->assertSame('receiverCif', $mapping['ClientCIF']);
        $this->assertArrayNotHasKey('ClientNume', $mapping);
    }

    public function testPartnerXmlColumnsAreRecognised(): void
    {
        $program = ['Denumire' => 'name', 'Cod_fiscal' => 'cui', 'Reg_comert' => 'registrationNumber', 'Cont_banca' => 'bankAccount', 'Telefon' => 'phone'];
        $targets = ['name', 'cui', 'registrationNumber', 'address', 'city', 'county', 'country', 'email', 'phone', 'bankName', 'bankAccount', 'clientCode', 'notes'];

        $mapping = PartnerHeaderAliases::suggest(
            ['cod', 'denumire', 'cod_fiscal', 'reg_com', 'tara', 'judet', 'localitate', 'adresa', 'cont_banca', 'banca', 'tel', 'email', 'discount', 'informatii', 'guid_cod'],
            $program,
            $targets,
        );

        $this->assertSame([
            'denumire' => 'name',
            'cod_fiscal' => 'cui',
            'cont_banca' => 'bankAccount',
            'cod' => 'clientCode',
            'reg_com' => 'registrationNumber',
            'tara' => 'country',
            'judet' => 'county',
            'localitate' => 'city',
            'adresa' => 'address',
            'banca' => 'bankName',
            'tel' => 'phone',
            'email' => 'email',
            'informatii' => 'notes',
        ], $mapping);
    }
}
