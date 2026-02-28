<?php

namespace App\DataFixtures;

use App\Entity\Organization;
use App\Entity\OrganizationInvitation;
use App\Entity\User;
use App\Enum\OrganizationRole;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class InvitationFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        // Pending invitation for org-1 (sent by admin)
        $invitation = (new OrganizationInvitation())
            ->setOrganization($this->getReference('org-1', Organization::class))
            ->setInvitedBy($this->getReference('user-1', User::class))
            ->setEmail('newuser@example.com')
            ->setRole(OrganizationRole::ACCOUNTANT);

        $manager->persist($invitation);
        $this->addReference('invitation-1', $invitation);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            OrganizationFixtures::class,
            UserFixtures::class,
        ];
    }
}
