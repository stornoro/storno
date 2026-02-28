<?php

namespace App\DataFixtures;

use App\Entity\AnafToken;
use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class AnafTokenFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        // Active ANAF token for admin user
        $t1 = (new AnafToken())
            ->setUser($this->getReference('user-1', User::class))
            ->setToken('eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9.demo_anaf_token_fixture_' . bin2hex(random_bytes(16)))
            ->setRefreshToken('refresh_' . bin2hex(random_bytes(32)))
            ->setExpireAt(new \DateTimeImmutable('+30 days'));
        $manager->persist($t1);

        // Expired ANAF token for regular user
        $t2 = (new AnafToken())
            ->setUser($this->getReference('user-2', User::class))
            ->setToken('eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9.expired_demo_' . bin2hex(random_bytes(16)))
            ->setRefreshToken('refresh_expired_' . bin2hex(random_bytes(32)))
            ->setExpireAt(new \DateTimeImmutable('-10 days'));
        $manager->persist($t2);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            UserFixtures::class,
        ];
    }
}
