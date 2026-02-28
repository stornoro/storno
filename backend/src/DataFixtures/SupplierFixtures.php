<?php

namespace App\DataFixtures;

use App\Entity\Company;
use App\Entity\Supplier;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class SupplierFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        $suppliers = [
            // Suppliers for company-1 (UEP)
            [
                'company' => 'company-1',
                'name' => 'MEGA IMAGE SRL',
                'cif' => '6719050',
                'vatCode' => 'RO6719050',
                'isVatPayer' => true,
                'registrationNumber' => 'J40/20397/1994',
                'address' => 'Bd. Timisoara 26',
                'city' => 'SECTOR6',
                'county' => 'B',
                'country' => 'RO',
                'email' => 'comenzi@mega-image.ro',
                'phone' => '0212345000',
                'bankName' => null,
                'bankAccount' => null,
                'source' => 'anaf_sync',
                'lastSyncedAt' => '-2 hours',
            ],
            [
                'company' => 'company-1',
                'name' => 'DEDEMAN SRL',
                'cif' => '4521785',
                'vatCode' => 'RO4521785',
                'isVatPayer' => true,
                'registrationNumber' => 'J04/234/1993',
                'address' => 'Str. Principala 1',
                'city' => 'Bacau',
                'county' => 'BC',
                'country' => 'RO',
                'email' => 'achizitii@dedeman.ro',
                'phone' => '0234567000',
                'bankName' => null,
                'bankAccount' => null,
                'source' => 'anaf_sync',
                'lastSyncedAt' => '-4 hours',
            ],
            [
                'company' => 'company-1',
                'name' => 'Popescu Ion PFA',
                'cif' => '99887766',
                'vatCode' => null,
                'isVatPayer' => false,
                'registrationNumber' => null,
                'address' => null,
                'city' => 'Bacau',
                'county' => 'BC',
                'country' => 'RO',
                'email' => null,
                'phone' => null,
                'bankName' => null,
                'bankAccount' => null,
                'source' => 'anaf_sync',
                'lastSyncedAt' => '-8 hours',
            ],
            // Suppliers for company-4 (Contabilitate Expert)
            [
                'company' => 'company-4',
                'name' => 'TECH INNOVATIONS SRL',
                'cif' => '33445566',
                'vatCode' => 'RO33445566',
                'isVatPayer' => true,
                'registrationNumber' => null,
                'address' => 'Str. Inovatiei 5',
                'city' => 'Timisoara',
                'county' => 'TM',
                'country' => 'RO',
                'email' => 'office@techinnovations.ro',
                'phone' => '0256789012',
                'bankName' => null,
                'bankAccount' => null,
                'source' => 'anaf_sync',
                'lastSyncedAt' => '-1 hour',
            ],
            // Additional suppliers for company-1 (UEP)
            [
                'company' => 'company-1',
                'name' => 'HILTI ROMANIA SRL',
                'cif' => '9398887',
                'vatCode' => 'RO9398887',
                'isVatPayer' => true,
                'registrationNumber' => 'J40/8765/2000',
                'address' => 'Str. Gara Herastrau 2',
                'city' => 'SECTOR2',
                'county' => 'B',
                'country' => 'RO',
                'email' => 'office@hilti.ro',
                'phone' => '0213151500',
                'bankName' => 'UniCredit Bank',
                'bankAccount' => 'RO45BACX0000000234567890',
                'source' => 'anaf_sync',
                'lastSyncedAt' => '-3 hours',
            ],
            [
                'company' => 'company-1',
                'name' => 'BOSCH REXROTH SRL',
                'cif' => '14753012',
                'vatCode' => 'RO14753012',
                'isVatPayer' => true,
                'registrationNumber' => 'J40/12345/2003',
                'address' => 'Bd. Preciziei 24',
                'city' => 'SECTOR6',
                'county' => 'B',
                'country' => 'RO',
                'email' => 'vanzari@boschrexroth.ro',
                'phone' => '0213055000',
                'bankName' => 'Deutsche Bank',
                'bankAccount' => 'RO67DEUT0000000123456789',
                'source' => 'anaf_sync',
                'lastSyncedAt' => '-5 hours',
            ],
            [
                'company' => 'company-1',
                'name' => 'SCHNEIDER ELECTRIC SRL',
                'cif' => '1573949',
                'vatCode' => 'RO1573949',
                'isVatPayer' => true,
                'registrationNumber' => 'J40/2345/1991',
                'address' => 'Bd. Timisoara 78',
                'city' => 'SECTOR6',
                'county' => 'B',
                'country' => 'RO',
                'email' => 'comenzi@schneider-electric.ro',
                'phone' => '0214057000',
                'bankName' => 'BNP Paribas',
                'bankAccount' => 'RO12BNPA0000000345678901',
                'source' => 'anaf_sync',
                'lastSyncedAt' => '-12 hours',
            ],
            // Suppliers for company-4 (Contabilitate Expert)
            [
                'company' => 'company-4',
                'name' => 'ORANGE ROMANIA SA',
                'cif' => '9010105',
                'vatCode' => 'RO9010105',
                'isVatPayer' => true,
                'registrationNumber' => 'J40/10178/1996',
                'address' => 'Bd. Lascar Catargiu 47-53',
                'city' => 'SECTOR1',
                'county' => 'B',
                'country' => 'RO',
                'email' => 'business@orange.ro',
                'phone' => '0374007000',
                'bankName' => 'BCR',
                'bankAccount' => 'RO34RNCB0082000234567890',
                'source' => 'manual',
                'lastSyncedAt' => '-24 hours',
            ],
            // Suppliers for company-6 (Ion Popescu PFA)
            [
                'company' => 'company-6',
                'name' => 'ENVATO PTY LTD',
                'cif' => null,
                'vatCode' => null,
                'isVatPayer' => false,
                'registrationNumber' => null,
                'address' => 'PO Box 16122',
                'city' => 'Melbourne',
                'county' => 'VIC',
                'country' => 'AU',
                'email' => 'accounts@envato.com',
                'phone' => null,
                'bankName' => null,
                'bankAccount' => null,
                'source' => 'manual',
                'lastSyncedAt' => '-48 hours',
            ],
            [
                'company' => 'company-6',
                'name' => 'HETZNER ONLINE GMBH',
                'cif' => null,
                'vatCode' => 'DE812871812',
                'isVatPayer' => true,
                'registrationNumber' => null,
                'address' => 'Industriestr. 25',
                'city' => 'Gunzenhausen',
                'county' => 'Bayern',
                'country' => 'DE',
                'email' => 'billing@hetzner.com',
                'phone' => null,
                'bankName' => null,
                'bankAccount' => null,
                'source' => 'manual',
                'lastSyncedAt' => '-36 hours',
            ],
        ];

        foreach ($suppliers as $i => $data) {
            $supplier = (new Supplier())
                ->setCompany($this->getReference($data['company'], Company::class))
                ->setName($data['name'])
                ->setCif($data['cif'])
                ->setVatCode($data['vatCode'])
                ->setIsVatPayer($data['isVatPayer'])
                ->setRegistrationNumber($data['registrationNumber'])
                ->setAddress($data['address'])
                ->setCity($data['city'])
                ->setCounty($data['county'])
                ->setCountry($data['country'])
                ->setEmail($data['email'])
                ->setPhone($data['phone'])
                ->setBankName($data['bankName'])
                ->setBankAccount($data['bankAccount'])
                ->setSource($data['source'])
                ->setLastSyncedAt(new \DateTimeImmutable($data['lastSyncedAt']));

            $manager->persist($supplier);
            $this->addReference('supplier-' . ($i + 1), $supplier);
        }

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            CompanyFixtures::class,
        ];
    }
}
