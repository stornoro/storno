<?php

namespace App\DataFixtures;

use App\Entity\BankAccount;
use App\Entity\Company;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class BankAccountFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        $bankAccounts = [
            // company-1 (UEP) — BT RON (default) + BRD EUR
            [
                'company' => 'company-1',
                'iban' => 'RO49BTRLRONCRT0123456789',
                'bankName' => 'Banca Transilvania',
                'currency' => 'RON',
                'isDefault' => true,
                'source' => 'manual',
            ],
            [
                'company' => 'company-1',
                'iban' => 'RO12BRDE040SV99887766554',
                'bankName' => 'BRD',
                'currency' => 'EUR',
                'isDefault' => false,
                'source' => 'manual',
            ],
            // company-2 (Rikko Steel) — BRD RON (default)
            [
                'company' => 'company-2',
                'iban' => 'RO12BRDE040SV12345678901',
                'bankName' => 'BRD',
                'currency' => 'RON',
                'isDefault' => true,
                'source' => 'manual',
            ],
            // company-4 (Contabilitate Expert) — BCR RON (default)
            [
                'company' => 'company-4',
                'iban' => 'RO34RNCB0082000123456789',
                'bankName' => 'BCR',
                'currency' => 'RON',
                'isDefault' => true,
                'source' => 'manual',
            ],
            // company-5 (Audit Partners) — Raiffeisen RON (default)
            [
                'company' => 'company-5',
                'iban' => 'RO56RZBR0000060012345678',
                'bankName' => 'Raiffeisen Bank',
                'currency' => 'RON',
                'isDefault' => true,
                'source' => 'manual',
            ],
            // company-6 (Ion Popescu PFA) — BT RON (default) + ING EUR
            [
                'company' => 'company-6',
                'iban' => 'RO78BTRLRONCRT9876543210',
                'bankName' => 'Banca Transilvania',
                'currency' => 'RON',
                'isDefault' => true,
                'source' => 'manual',
            ],
            [
                'company' => 'company-6',
                'iban' => 'RO23INGB0001000987654321',
                'bankName' => 'ING Bank',
                'currency' => 'EUR',
                'isDefault' => false,
                'source' => 'manual',
            ],
        ];

        foreach ($bankAccounts as $i => $data) {
            $bankAccount = (new BankAccount())
                ->setCompany($this->getReference($data['company'], Company::class))
                ->setIban($data['iban'])
                ->setBankName($data['bankName'])
                ->setCurrency($data['currency'])
                ->setIsDefault($data['isDefault'])
                ->setSource($data['source']);

            $manager->persist($bankAccount);
            $this->addReference('bank-account-' . ($i + 1), $bankAccount);
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
