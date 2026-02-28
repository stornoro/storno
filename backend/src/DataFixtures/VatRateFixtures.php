<?php

namespace App\DataFixtures;

use App\Entity\Company;
use App\Entity\VatRate;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class VatRateFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        $vatRates = [
            // company-1 (UEP) — full set of 4 rates
            [
                'company' => 'company-1',
                'rate' => '21.00',
                'label' => 'TVA 21%',
                'categoryCode' => 'S',
                'isDefault' => true,
                'isActive' => true,
                'position' => 0,
            ],
            [
                'company' => 'company-1',
                'rate' => '9.00',
                'label' => 'TVA 9%',
                'categoryCode' => 'S',
                'isDefault' => false,
                'isActive' => true,
                'position' => 1,
            ],
            [
                'company' => 'company-1',
                'rate' => '5.00',
                'label' => 'TVA 5%',
                'categoryCode' => 'S',
                'isDefault' => false,
                'isActive' => true,
                'position' => 2,
            ],
            [
                'company' => 'company-1',
                'rate' => '0.00',
                'label' => 'Scutit',
                'categoryCode' => 'E',
                'isDefault' => false,
                'isActive' => true,
                'position' => 3,
            ],
            // company-2 (Rikko Steel) — minimal set
            [
                'company' => 'company-2',
                'rate' => '21.00',
                'label' => 'TVA 21%',
                'categoryCode' => 'S',
                'isDefault' => true,
                'isActive' => true,
                'position' => 0,
            ],
            // company-4 (Contabilitate Expert) — full set of 4 rates
            [
                'company' => 'company-4',
                'rate' => '21.00',
                'label' => 'TVA 21%',
                'categoryCode' => 'S',
                'isDefault' => true,
                'isActive' => true,
                'position' => 0,
            ],
            [
                'company' => 'company-4',
                'rate' => '9.00',
                'label' => 'TVA 9%',
                'categoryCode' => 'S',
                'isDefault' => false,
                'isActive' => true,
                'position' => 1,
            ],
            [
                'company' => 'company-4',
                'rate' => '5.00',
                'label' => 'TVA 5%',
                'categoryCode' => 'S',
                'isDefault' => false,
                'isActive' => true,
                'position' => 2,
            ],
            [
                'company' => 'company-4',
                'rate' => '0.00',
                'label' => 'Scutit',
                'categoryCode' => 'E',
                'isDefault' => false,
                'isActive' => true,
                'position' => 3,
            ],
            // company-5 (Audit Partners) — minimal set
            [
                'company' => 'company-5',
                'rate' => '21.00',
                'label' => 'TVA 21%',
                'categoryCode' => 'S',
                'isDefault' => true,
                'isActive' => true,
                'position' => 0,
            ],
            // company-6 (Ion Popescu PFA) — full set of 4 rates
            [
                'company' => 'company-6',
                'rate' => '21.00',
                'label' => 'TVA 21%',
                'categoryCode' => 'S',
                'isDefault' => true,
                'isActive' => true,
                'position' => 0,
            ],
            [
                'company' => 'company-6',
                'rate' => '9.00',
                'label' => 'TVA 9%',
                'categoryCode' => 'S',
                'isDefault' => false,
                'isActive' => true,
                'position' => 1,
            ],
            [
                'company' => 'company-6',
                'rate' => '5.00',
                'label' => 'TVA 5%',
                'categoryCode' => 'S',
                'isDefault' => false,
                'isActive' => true,
                'position' => 2,
            ],
            [
                'company' => 'company-6',
                'rate' => '0.00',
                'label' => 'Scutit',
                'categoryCode' => 'E',
                'isDefault' => false,
                'isActive' => true,
                'position' => 3,
            ],
        ];

        foreach ($vatRates as $i => $data) {
            $vatRate = (new VatRate())
                ->setCompany($this->getReference($data['company'], Company::class))
                ->setRate($data['rate'])
                ->setLabel($data['label'])
                ->setCategoryCode($data['categoryCode'])
                ->setIsDefault($data['isDefault'])
                ->setIsActive($data['isActive'])
                ->setPosition($data['position']);

            $manager->persist($vatRate);
            $this->addReference('vat-rate-' . ($i + 1), $vatRate);
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
