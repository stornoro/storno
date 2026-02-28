<?php

namespace App\DataFixtures;

use App\Entity\Company;
use App\Entity\Product;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class ProductFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        $products = [
            // Products for company-1 (UEP)
            ['company' => 'company-1', 'name' => 'Echipament hidraulic', 'code' => 'EH-001', 'unit' => 'buc', 'price' => '15000.00', 'vat' => '21.00', 'vatCode' => 'S', 'isService' => false],
            ['company' => 'company-1', 'name' => 'Piese schimb excavator', 'code' => 'PS-001', 'unit' => 'buc', 'price' => '2500.00', 'vat' => '21.00', 'vatCode' => 'S', 'isService' => false],
            ['company' => 'company-1', 'name' => 'Servicii montaj', 'code' => 'SM-001', 'unit' => 'ora', 'price' => '250.00', 'vat' => '21.00', 'vatCode' => 'S', 'isService' => true],
            ['company' => 'company-1', 'name' => 'Transport utilaje', 'code' => 'TU-001', 'unit' => 'km', 'price' => '8.50', 'vat' => '21.00', 'vatCode' => 'S', 'isService' => true],
            ['company' => 'company-1', 'name' => 'Revizie tehnica', 'code' => 'RT-001', 'unit' => 'buc', 'price' => '1200.00', 'vat' => '21.00', 'vatCode' => 'S', 'isService' => true],
            // Products for company-4 (Contabilitate Expert)
            ['company' => 'company-4', 'name' => 'Servicii contabilitate lunara', 'code' => 'SC-001', 'unit' => 'luna', 'price' => '800.00', 'vat' => '21.00', 'vatCode' => 'S', 'isService' => true],
            ['company' => 'company-4', 'name' => 'Declaratii fiscale', 'code' => 'DF-001', 'unit' => 'buc', 'price' => '150.00', 'vat' => '21.00', 'vatCode' => 'S', 'isService' => true],
            ['company' => 'company-4', 'name' => 'Consultanta fiscala', 'code' => 'CF-001', 'unit' => 'ora', 'price' => '300.00', 'vat' => '21.00', 'vatCode' => 'S', 'isService' => true],
            // Products for company-6 (Ion Popescu PFA)
            ['company' => 'company-6', 'name' => 'Dezvoltare web', 'code' => 'DW-001', 'unit' => 'ora', 'price' => '200.00', 'vat' => '21.00', 'vatCode' => 'S', 'isService' => true],
            ['company' => 'company-6', 'name' => 'Design UI/UX', 'code' => 'DU-001', 'unit' => 'ora', 'price' => '180.00', 'vat' => '21.00', 'vatCode' => 'S', 'isService' => true],
            ['company' => 'company-6', 'name' => 'Mentenanta site', 'code' => 'MS-001', 'unit' => 'luna', 'price' => '500.00', 'vat' => '21.00', 'vatCode' => 'S', 'isService' => true],
            // Additional products for company-1 (UEP)
            ['company' => 'company-1', 'name' => 'Pompa hidraulica', 'code' => 'PH-001', 'unit' => 'buc', 'price' => '8500.00', 'vat' => '21.00', 'vatCode' => 'S', 'isService' => false],
            ['company' => 'company-1', 'name' => 'Compresor industrial', 'code' => 'CI-001', 'unit' => 'buc', 'price' => '12000.00', 'vat' => '21.00', 'vatCode' => 'S', 'isService' => false],
            ['company' => 'company-1', 'name' => 'Filtre hidraulice set', 'code' => 'FH-001', 'unit' => 'set', 'price' => '450.00', 'vat' => '21.00', 'vatCode' => 'S', 'isService' => false],
            ['company' => 'company-1', 'name' => 'Consultanta tehnica', 'code' => 'CT-001', 'unit' => 'ora', 'price' => '350.00', 'vat' => '21.00', 'vatCode' => 'S', 'isService' => true],
            ['company' => 'company-1', 'name' => 'Inspectie echipamente', 'code' => 'IE-001', 'unit' => 'buc', 'price' => '2000.00', 'vat' => '21.00', 'vatCode' => 'S', 'isService' => true],
            // Additional products for company-4 (Contabilitate Expert)
            ['company' => 'company-4', 'name' => 'Consultanta resurse umane', 'code' => 'CRU-001', 'unit' => 'ora', 'price' => '200.00', 'vat' => '21.00', 'vatCode' => 'S', 'isService' => true],
            ['company' => 'company-4', 'name' => 'Audit financiar', 'code' => 'AF-001', 'unit' => 'buc', 'price' => '3500.00', 'vat' => '21.00', 'vatCode' => 'S', 'isService' => true],
            // Additional products for company-6 (Ion Popescu PFA)
            ['company' => 'company-6', 'name' => 'Optimizare SEO', 'code' => 'SEO-001', 'unit' => 'luna', 'price' => '800.00', 'vat' => '21.00', 'vatCode' => 'S', 'isService' => true],
            ['company' => 'company-6', 'name' => 'Hosting web premium', 'code' => 'HW-001', 'unit' => 'luna', 'price' => '150.00', 'vat' => '21.00', 'vatCode' => 'S', 'isService' => true],
            ['company' => 'company-6', 'name' => 'Certificat SSL', 'code' => 'SSL-001', 'unit' => 'buc', 'price' => '250.00', 'vat' => '21.00', 'vatCode' => 'S', 'isService' => true],
        ];

        foreach ($products as $i => $data) {
            $product = (new Product())
                ->setCompany($this->getReference($data['company'], Company::class))
                ->setName($data['name'])
                ->setCode($data['code'])
                ->setUnitOfMeasure($data['unit'])
                ->setDefaultPrice($data['price'])
                ->setCurrency('RON')
                ->setVatRate($data['vat'])
                ->setVatCategoryCode($data['vatCode'])
                ->setIsService($data['isService'])
                ->setIsActive(true)
                ->setSource('anaf_sync')
                ->setLastSyncedAt(new \DateTimeImmutable('-' . rand(1, 48) . ' hours'));

            $manager->persist($product);
            $this->addReference('product-' . ($i + 1), $product);
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
