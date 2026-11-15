<?php

namespace App\Tests\Unit\Import;

use App\Service\Import\Mapper\GenericClientMapper;
use App\Service\Import\Mapper\OblioClientMapper;
use App\Service\Import\Mapper\SupplierMapperAdapter;
use App\Service\Import\Persister\SupplierPersister;
use PHPUnit\Framework\TestCase;

class SupplierMapperAdapterTest extends TestCase
{
    public function testWrapsTheClientMapperOfTheSameSource(): void
    {
        $adapter = new SupplierMapperAdapter(new OblioClientMapper());

        self::assertSame('oblio', $adapter->getSource());
        self::assertSame('suppliers', $adapter->getImportType());
        self::assertSame(['name'], $adapter->getRequiredFields());
        // client-only fields are not offered for suppliers
        self::assertArrayNotHasKey('clientCode', $adapter->getTargetFields());
        self::assertArrayNotHasKey('cnp', $adapter->getTargetFields());
        self::assertNotContains('contactPerson', $adapter->getDefaultMapping());
        self::assertSame('name', $adapter->getDefaultMapping()['Nume']);
    }

    public function testSuggestsKnownColumnsAndGuessesRenamedOnes(): void
    {
        $adapter = new SupplierMapperAdapter(new OblioClientMapper());
        $mapping = $adapter->suggestMapping(['Nume', 'CUI', 'Localitate', 'Județ', 'Cont bancar', 'Coloana necunoscuta']);

        self::assertSame('name', $mapping['Nume']);
        self::assertSame('cui', $mapping['CUI']);
        self::assertSame('city', $mapping['Localitate']);
        self::assertSame('county', $mapping['Județ']);
        self::assertSame('bankAccount', $mapping['Cont bancar']);
        self::assertArrayNotHasKey('Coloana necunoscuta', $mapping);
    }

    public function testPlainSpreadsheetGetsASuggestionToo(): void
    {
        $adapter = new SupplierMapperAdapter(new GenericClientMapper());
        $mapping = $adapter->suggestMapping(['Denumire furnizor', 'Cod fiscal', 'E-mail', 'IBAN']);

        self::assertSame(
            ['Denumire furnizor' => 'name', 'Cod fiscal' => 'cui', 'E-mail' => 'email', 'IBAN' => 'bankAccount'],
            $mapping,
        );
    }

    public function testEachTargetIsSuggestedOnce(): void
    {
        $adapter = new SupplierMapperAdapter(new GenericClientMapper());
        $mapping = $adapter->suggestMapping(['Denumire', 'Nume firma']);

        self::assertSame(['Denumire' => 'name'], $mapping);
    }

    public function testMapRowNormalisesTheFiscalCodeAndDropsClientNotions(): void
    {
        $adapter = new SupplierMapperAdapter(new OblioClientMapper());
        $row = $adapter->mapRow(
            ['Nume' => 'Exemplu SRL', 'CUI' => 'RO12345678', 'Contact' => 'Ion Popescu'],
            ['Nume' => 'name', 'CUI' => 'cui', 'Contact' => 'contactPerson'],
        );

        self::assertSame('Exemplu SRL', $row['name']);
        self::assertSame('12345678', $row['cui']);
        self::assertSame('RO12345678', $row['vatCode']);
        self::assertTrue($row['isVatPayer']);
        self::assertSame('RO', $row['country']);
        self::assertArrayNotHasKey('contactPerson', $row);
        self::assertArrayNotHasKey('type', $row);
    }

    public function testFiscalCodeIsComparedTheWaySuppliersStoreIt(): void
    {
        self::assertSame('12345678', SupplierPersister::normalizeCif('RO12345678'));
        self::assertSame('12345678', SupplierPersister::normalizeCif(' ro 12345678 '));
        self::assertSame('12345678', SupplierPersister::normalizeCif('12345678'));
        self::assertSame('DE111111111', SupplierPersister::normalizeCif('de111111111'));
        self::assertNull(SupplierPersister::normalizeCif('-'));
        self::assertNull(SupplierPersister::normalizeCif(null));
    }
}
