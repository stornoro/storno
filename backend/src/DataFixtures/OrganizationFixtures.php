<?php

namespace App\DataFixtures;

use App\Entity\Organization;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class OrganizationFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $orgs = [
            ['name' => 'Storno.ro Demo', 'slug' => 'storno-demo', 'plan' => 'professional', 'maxUsers' => 10, 'maxCompanies' => 10],
            ['name' => 'Contabilitate SRL', 'slug' => 'contabilitate-srl', 'plan' => 'starter', 'maxUsers' => 5, 'maxCompanies' => 5],
            ['name' => 'Freelancer Ion', 'slug' => 'freelancer-ion', 'plan' => 'starter', 'maxUsers' => 3, 'maxCompanies' => 3],
        ];

        foreach ($orgs as $i => $data) {
            $org = (new Organization())
                ->setName($data['name'])
                ->setSlug($data['slug'])
                ->setPlan($data['plan'])
                ->setMaxUsers($data['maxUsers'])
                ->setMaxCompanies($data['maxCompanies'])
                ->setIsActive(true)
                ->setSettings([]);

            $manager->persist($org);
            $this->addReference('org-' . ($i + 1), $org);
        }

        $manager->flush();
    }
}
