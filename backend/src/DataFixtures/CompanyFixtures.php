<?php

namespace App\DataFixtures;

use App\Entity\Company;
use App\Entity\Organization;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class CompanyFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        $companies = [
            // Org 1 (Storno.ro Demo) - 3 companies
            [
                'org' => 'org-1',
                'name' => 'UNIVERSAL EQUIPMENT PROJECTS SRL',
                'cif' => 31385365,
                'regNumber' => 'J04/1234/2015',
                'vatPayer' => true,
                'vatCode' => 'RO31385365',
                'address' => 'Str. Nicolae Balcescu 5 B',
                'city' => 'Bacau',
                'state' => 'BC',
                'country' => 'RO',
                'phone' => '0234567890',
                'email' => 'office@uep.ro',
                'bankName' => 'Banca Transilvania',
                'bankAccount' => 'RO49BTRLRONCRT0123456789',
                'bankBic' => 'BTRLRO22',
                'currency' => 'RON',
                'syncEnabled' => true,
                'syncDaysBack' => 60,
                'lastSyncedAt' => '-1 hour',
                'vatOnCollection' => true,
            ],
            [
                'org' => 'org-1',
                'name' => 'RIKKO STEEL SRL',
                'cif' => 24899169,
                'regNumber' => 'J04/5678/2012',
                'vatPayer' => true,
                'vatCode' => 'RO24899169',
                'address' => 'Str. Stefan Cel Mare 28 C',
                'city' => 'Bacau',
                'state' => 'BC',
                'country' => 'RO',
                'phone' => '0234111222',
                'email' => 'contact@rikkosteel.ro',
                'bankName' => 'BRD',
                'bankAccount' => 'RO12BRDE040SV12345678901',
                'bankBic' => 'BRDEROBU',
                'currency' => 'RON',
                'syncEnabled' => true,
                'syncDaysBack' => 30,
                'lastSyncedAt' => '-2 hours',
            ],
            [
                'org' => 'org-1',
                'name' => 'ROSISTEM.RO SRL',
                'cif' => 15117808,
                'regNumber' => 'J40/9012/2008',
                'vatPayer' => true,
                'vatCode' => 'RO15117808',
                'address' => 'Str. Titus 25',
                'city' => 'SECTOR1',
                'state' => 'B',
                'country' => 'RO',
                'phone' => '0212345678',
                'email' => 'office@rosistem.ro',
                'bankName' => 'ING Bank',
                'bankAccount' => 'RO23INGB0001000123456789',
                'bankBic' => 'INGBROBU',
                'currency' => 'RON',
                'syncEnabled' => false,
                'syncDaysBack' => 60,
            ],
            // Org 2 (Contabilitate SRL) - 2 companies
            [
                'org' => 'org-2',
                'name' => 'CONTABILITATE EXPERT SRL',
                'cif' => 11223344,
                'regNumber' => 'J35/2345/2018',
                'vatPayer' => true,
                'vatCode' => 'RO11223344',
                'address' => 'Str. Libertatii 10',
                'city' => 'Timisoara',
                'state' => 'TM',
                'country' => 'RO',
                'phone' => '0256123456',
                'email' => 'expert@contabilitate.ro',
                'bankName' => 'BCR',
                'bankAccount' => 'RO34RNCB0082000123456789',
                'bankBic' => 'RNCBROBU',
                'currency' => 'RON',
                'syncEnabled' => true,
                'syncDaysBack' => 90,
                'lastSyncedAt' => '-3 hours',
            ],
            [
                'org' => 'org-2',
                'name' => 'AUDIT PARTNERS SRL',
                'cif' => 55667788,
                'regNumber' => 'J12/3456/2020',
                'vatPayer' => false,
                'vatCode' => null,
                'address' => 'Str. Eroilor 45',
                'city' => 'Cluj-Napoca',
                'state' => 'CJ',
                'country' => 'RO',
                'phone' => '0264987654',
                'email' => 'contact@auditpartners.ro',
                'bankName' => 'Raiffeisen Bank',
                'bankAccount' => 'RO56RZBR0000060012345678',
                'bankBic' => 'RZBRROBU',
                'currency' => 'RON',
                'syncEnabled' => false,
            ],
            // Org 3 (Freelancer Ion) - 1 company
            [
                'org' => 'org-3',
                'name' => 'ION POPESCU PFA',
                'cif' => 12345678,
                'regNumber' => 'F04/123/2022',
                'vatPayer' => false,
                'vatCode' => null,
                'address' => 'Str. Mihai Viteazu 12',
                'city' => 'Iasi',
                'state' => 'IS',
                'country' => 'RO',
                'phone' => '0732100200',
                'email' => 'ion.popescu@gmail.com',
                'bankName' => 'Banca Transilvania',
                'bankAccount' => 'RO78BTRLRONCRT9876543210',
                'bankBic' => 'BTRLRO22',
                'currency' => 'RON',
                'syncEnabled' => true,
                'syncDaysBack' => 30,
                'lastSyncedAt' => '-6 hours',
            ],
        ];

        foreach ($companies as $i => $data) {
            $company = (new Company())
                ->setOrganization($this->getReference($data['org'], Organization::class))
                ->setName($data['name'])
                ->setCif($data['cif'])
                ->setRegistrationNumber($data['regNumber'])
                ->setVatPayer($data['vatPayer'])
                ->setVatCode($data['vatCode'])
                ->setAddress($data['address'])
                ->setCity($data['city'])
                ->setState($data['state'])
                ->setCountry($data['country'])
                ->setPhone($data['phone'])
                ->setEmail($data['email'])
                ->setBankName($data['bankName'])
                ->setBankAccount($data['bankAccount'])
                ->setBankBic($data['bankBic'])
                ->setDefaultCurrency($data['currency'])
                ->setSyncEnabled($data['syncEnabled'] ?? false)
                ->setSyncDaysBack($data['syncDaysBack'] ?? 60)
                ->setVatOnCollection($data['vatOnCollection'] ?? false);

            if (isset($data['lastSyncedAt'])) {
                $company->setLastSyncedAt(new \DateTimeImmutable($data['lastSyncedAt']));
            }

            $manager->persist($company);
            $this->addReference('company-' . ($i + 1), $company);
        }

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            OrganizationFixtures::class,
        ];
    }
}
