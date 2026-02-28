<?php

namespace App\DataFixtures;

use App\Entity\Client;
use App\Entity\Company;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class ClientFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        $clients = [
            // Clients for company-1 (UEP)
            [
                'company' => 'company-1',
                'type' => 'company',
                'name' => 'MEGA IMAGE SRL',
                'cui' => '6719050',
                'vatCode' => 'RO6719050',
                'isVatPayer' => true,
                'regNumber' => 'J40/20397/1994',
                'address' => 'Bd. Timisoara 26',
                'city' => 'SECTOR6',
                'county' => 'B',
                'country' => 'RO',
                'postalCode' => '061344',
                'email' => 'comenzi@mega-image.ro',
                'phone' => '0212345000',
                'bankName' => 'ING Bank',
                'bankAccount' => 'RO12INGB0001000234567890',
                'paymentTermDays' => 30,
            ],
            [
                'company' => 'company-1',
                'type' => 'company',
                'name' => 'DEDEMAN SRL',
                'cui' => '4521785',
                'vatCode' => 'RO4521785',
                'isVatPayer' => true,
                'regNumber' => 'J04/234/1993',
                'address' => 'Str. Principala 1',
                'city' => 'Bacau',
                'county' => 'BC',
                'country' => 'RO',
                'postalCode' => '600001',
                'email' => 'achizitii@dedeman.ro',
                'phone' => '0234567000',
                'bankName' => 'BRD',
                'bankAccount' => 'RO34BRDE040SV98765432100',
                'paymentTermDays' => 15,
            ],
            [
                'company' => 'company-1',
                'type' => 'individual',
                'name' => 'Popescu Alexandru',
                'cui' => null,
                'vatCode' => null,
                'isVatPayer' => false,
                'regNumber' => null,
                'address' => 'Str. Florilor 12',
                'city' => 'Bacau',
                'county' => 'BC',
                'country' => 'RO',
                'postalCode' => '600100',
                'email' => 'alex.popescu@gmail.com',
                'phone' => '0722333444',
                'bankName' => null,
                'bankAccount' => null,
                'paymentTermDays' => null,
            ],
            // Clients for company-4 (Contabilitate Expert)
            [
                'company' => 'company-4',
                'type' => 'company',
                'name' => 'TECH INNOVATIONS SRL',
                'cui' => '33445566',
                'vatCode' => 'RO33445566',
                'isVatPayer' => true,
                'regNumber' => 'J35/7890/2021',
                'address' => 'Str. Inovatiei 5',
                'city' => 'Timisoara',
                'county' => 'TM',
                'country' => 'RO',
                'postalCode' => '300001',
                'email' => 'office@techinnovations.ro',
                'phone' => '0256789012',
                'bankName' => 'Banca Transilvania',
                'bankAccount' => 'RO67BTRLRONCRT5566778899',
                'paymentTermDays' => 45,
            ],
            // Clients for company-6 (Ion Popescu PFA)
            [
                'company' => 'company-6',
                'type' => 'company',
                'name' => 'WEBDEV AGENCY SRL',
                'cui' => '44556677',
                'vatCode' => 'RO44556677',
                'isVatPayer' => true,
                'regNumber' => 'J22/1234/2019',
                'address' => 'Str. Softului 8',
                'city' => 'Iasi',
                'county' => 'IS',
                'country' => 'RO',
                'postalCode' => '700001',
                'email' => 'office@webdev.ro',
                'phone' => '0232456789',
                'bankName' => 'BCR',
                'bankAccount' => 'RO89RNCB0082000567890123',
                'paymentTermDays' => 14,
            ],
            [
                'company' => 'company-6',
                'type' => 'individual',
                'name' => 'Ionescu Cristian',
                'cui' => null,
                'vatCode' => null,
                'isVatPayer' => false,
                'regNumber' => null,
                'address' => 'Bd. Independentei 20',
                'city' => 'Iasi',
                'county' => 'IS',
                'country' => 'RO',
                'postalCode' => '700200',
                'email' => 'cristian.ionescu@yahoo.com',
                'phone' => '0755666777',
                'bankName' => null,
                'bankAccount' => null,
                'paymentTermDays' => null,
            ],
            // Additional clients for company-1 (UEP)
            [
                'company' => 'company-1',
                'type' => 'company',
                'name' => 'LIDL ROMANIA SCS',
                'cui' => '16329050',
                'vatCode' => 'RO16329050',
                'isVatPayer' => true,
                'regNumber' => 'J40/2681/2004',
                'address' => 'Str. Industriilor 2',
                'city' => 'Chiajna',
                'county' => 'IF',
                'country' => 'RO',
                'postalCode' => '077040',
                'email' => 'achizitii@lidl.ro',
                'phone' => '0214057500',
                'bankName' => 'UniCredit Bank',
                'bankAccount' => 'RO45BACX0000000123456789',
                'paymentTermDays' => 30,
            ],
            [
                'company' => 'company-1',
                'type' => 'company',
                'name' => 'HORNBACH BAUMARKT SRL',
                'cui' => '18274432',
                'vatCode' => 'RO18274432',
                'isVatPayer' => true,
                'regNumber' => 'J23/3456/2005',
                'address' => 'Sos. Berceni 2',
                'city' => 'SECTOR4',
                'county' => 'B',
                'country' => 'RO',
                'postalCode' => '041901',
                'email' => 'aprovizionare@hornbach.ro',
                'phone' => '0213201000',
                'bankName' => 'Raiffeisen Bank',
                'bankAccount' => 'RO78RZBR0000060087654321',
                'paymentTermDays' => 45,
            ],
            [
                'company' => 'company-1',
                'type' => 'company',
                'name' => 'EMAG.RO SRL',
                'cui' => '14399840',
                'vatCode' => 'RO14399840',
                'isVatPayer' => true,
                'regNumber' => 'J40/12863/2001',
                'address' => 'Sos. Virtutii 148',
                'city' => 'SECTOR6',
                'county' => 'B',
                'country' => 'RO',
                'postalCode' => '060784',
                'email' => 'marketplace@emag.ro',
                'phone' => '0314056789',
                'bankName' => 'ING Bank',
                'bankAccount' => 'RO11INGB0001000567890123',
                'paymentTermDays' => 30,
            ],
            [
                'company' => 'company-1',
                'type' => 'company',
                'name' => 'ALTEX ROMANIA SRL',
                'cui' => '1269480',
                'vatCode' => 'RO1269480',
                'isVatPayer' => true,
                'regNumber' => 'J12/1234/1992',
                'address' => 'Str. Garii 2',
                'city' => 'Piatra Neamt',
                'county' => 'NT',
                'country' => 'RO',
                'postalCode' => '610136',
                'email' => 'achizitii@altex.ro',
                'phone' => '0233213456',
                'bankName' => 'BCR',
                'bankAccount' => 'RO34RNCB0082000112233445',
                'paymentTermDays' => 20,
            ],
            [
                'company' => 'company-1',
                'type' => 'individual',
                'name' => 'Gheorghe Maria',
                'cui' => null,
                'vatCode' => null,
                'isVatPayer' => false,
                'regNumber' => null,
                'address' => 'Str. Trandafirilor 8',
                'city' => 'Onesti',
                'county' => 'BC',
                'country' => 'RO',
                'postalCode' => '601100',
                'email' => 'maria.gheorghe@yahoo.com',
                'phone' => '0744112233',
                'bankName' => null,
                'bankAccount' => null,
                'paymentTermDays' => null,
            ],
            // Additional clients for company-4 (Contabilitate Expert)
            [
                'company' => 'company-4',
                'type' => 'company',
                'name' => 'CURSOR DIGITAL SRL',
                'cui' => '42567890',
                'vatCode' => 'RO42567890',
                'isVatPayer' => true,
                'regNumber' => 'J35/4567/2022',
                'address' => 'Str. Memorandumului 12',
                'city' => 'Timisoara',
                'county' => 'TM',
                'country' => 'RO',
                'postalCode' => '300040',
                'email' => 'office@cursor-digital.ro',
                'phone' => '0256334455',
                'bankName' => 'Banca Transilvania',
                'bankAccount' => 'RO56BTRLRONCRT1122334455',
                'paymentTermDays' => 30,
            ],
            // Additional clients for company-6 (Ion Popescu PFA)
            [
                'company' => 'company-6',
                'type' => 'company',
                'name' => 'MEDIPRINT SRL',
                'cui' => '55778899',
                'vatCode' => 'RO55778899',
                'isVatPayer' => true,
                'regNumber' => 'J22/5678/2020',
                'address' => 'Str. Universitatii 3',
                'city' => 'Iasi',
                'county' => 'IS',
                'country' => 'RO',
                'postalCode' => '700050',
                'email' => 'contact@mediprint.ro',
                'phone' => '0232445566',
                'bankName' => 'BRD',
                'bankAccount' => 'RO89BRDE240SV99887766554',
                'paymentTermDays' => 14,
            ],
            [
                'company' => 'company-6',
                'type' => 'individual',
                'name' => 'Stanciu Andrei',
                'cui' => null,
                'vatCode' => null,
                'isVatPayer' => false,
                'regNumber' => null,
                'address' => 'Str. Pacurari 45',
                'city' => 'Iasi',
                'county' => 'IS',
                'country' => 'RO',
                'postalCode' => '700511',
                'email' => 'andrei.stanciu@gmail.com',
                'phone' => '0766998877',
                'bankName' => null,
                'bankAccount' => null,
                'paymentTermDays' => null,
            ],
        ];

        foreach ($clients as $i => $data) {
            $client = (new Client())
                ->setCompany($this->getReference($data['company'], Company::class))
                ->setType($data['type'])
                ->setName($data['name'])
                ->setCui($data['cui'])
                ->setVatCode($data['vatCode'])
                ->setIsVatPayer($data['isVatPayer'])
                ->setRegistrationNumber($data['regNumber'])
                ->setAddress($data['address'])
                ->setCity($data['city'])
                ->setCounty($data['county'])
                ->setCountry($data['country'])
                ->setPostalCode($data['postalCode'])
                ->setEmail($data['email'])
                ->setPhone($data['phone'])
                ->setBankName($data['bankName'])
                ->setBankAccount($data['bankAccount'])
                ->setDefaultPaymentTermDays($data['paymentTermDays'])
                ->setSource('anaf_sync')
                ->setLastSyncedAt(new \DateTimeImmutable('-' . rand(1, 48) . ' hours'));

            $manager->persist($client);
            $this->addReference('client-' . ($i + 1), $client);
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
