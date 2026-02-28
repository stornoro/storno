<?php

namespace App\DataFixtures;

use App\Entity\Company;
use App\Entity\DocumentSeries;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class DocumentSeriesFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        $series = [
            // company-1 (UEP) — all four document types
            [
                'company' => 'company-1',
                'prefix' => 'UEP',
                'currentNumber' => 15,
                'type' => 'invoice',
                'active' => true,
                'source' => 'manual',
                'ref' => 'series-uep',
            ],
            [
                'company' => 'company-1',
                'prefix' => 'UEPCN',
                'currentNumber' => 2,
                'type' => 'credit_note',
                'active' => true,
                'source' => 'manual',
                'ref' => 'series-uepcn',
            ],
            [
                'company' => 'company-1',
                'prefix' => 'UEPPF',
                'currentNumber' => 4,
                'type' => 'proforma',
                'active' => true,
                'source' => 'manual',
                'ref' => 'series-ueppf',
            ],
            [
                'company' => 'company-1',
                'prefix' => 'UEPAV',
                'currentNumber' => 6,
                'type' => 'delivery_note',
                'active' => true,
                'source' => 'manual',
                'ref' => 'series-uepav',
            ],
            [
                'company' => 'company-1',
                'prefix' => 'UEPBON',
                'currentNumber' => 5,
                'type' => 'receipt',
                'active' => true,
                'source' => 'manual',
                'ref' => 'series-uepbon',
            ],
            // company-2 (Rikko Steel) — invoice only
            [
                'company' => 'company-2',
                'prefix' => 'RKS',
                'currentNumber' => 2,
                'type' => 'invoice',
                'active' => true,
                'source' => 'manual',
                'ref' => 'series-rks',
            ],
            // company-4 (Contabilitate Expert) — invoice + proforma
            [
                'company' => 'company-4',
                'prefix' => 'CE',
                'currentNumber' => 3,
                'type' => 'invoice',
                'active' => true,
                'source' => 'manual',
                'ref' => 'series-ce',
            ],
            [
                'company' => 'company-4',
                'prefix' => 'CEPF',
                'currentNumber' => 2,
                'type' => 'proforma',
                'active' => true,
                'source' => 'manual',
                'ref' => 'series-cepf',
            ],
            // company-6 (Ion Popescu PFA) — invoice + proforma
            [
                'company' => 'company-6',
                'prefix' => 'IP',
                'currentNumber' => 4,
                'type' => 'invoice',
                'active' => true,
                'source' => 'manual',
                'ref' => 'series-ip',
            ],
            [
                'company' => 'company-6',
                'prefix' => 'IPPF',
                'currentNumber' => 2,
                'type' => 'proforma',
                'active' => true,
                'source' => 'manual',
                'ref' => 'series-ippf',
            ],
        ];

        foreach ($series as $data) {
            $documentSeries = (new DocumentSeries())
                ->setCompany($this->getReference($data['company'], Company::class))
                ->setPrefix($data['prefix'])
                ->setCurrentNumber($data['currentNumber'])
                ->setType($data['type'])
                ->setActive($data['active'])
                ->setSource($data['source']);

            $manager->persist($documentSeries);
            $this->addReference($data['ref'], $documentSeries);
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
